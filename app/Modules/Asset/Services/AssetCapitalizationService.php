<?php

namespace App\Modules\Asset\Services;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCapitalization;
use App\Modules\Asset\Models\AssetCapitalizationLine;
use App\Modules\Asset\Models\AssetHistory;
use App\Modules\Asset\Models\AssetValueEvent;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Models\PurchaseDocumentLine;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Capitalization owns the Asset subledger and calls Accounting for every GL write. */
final class AssetCapitalizationService
{
    public function __construct(private readonly JournalPostingService $journals) {}

    public function createDraft(Branch $branch, array $attributes, User $actor): AssetCapitalization
    {
        $values = $this->validateDraft($attributes);
        $this->assertDraftSource($branch, $values);

        return DB::transaction(function () use ($branch, $values, $actor): AssetCapitalization {
            $capitalization = AssetCapitalization::query()->create([
                ...collect($values)->except('lines')->all(),
                'branch_id' => $branch->id,
                'status' => 'DRAFT',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            $this->createLines($capitalization, $branch, $values['lines'], $actor);

            return $capitalization->fresh('lines');
        }, 3);
    }

    public function submit(AssetCapitalization $capitalization, User $actor): AssetCapitalization
    {
        return DB::transaction(function () use ($capitalization, $actor): AssetCapitalization {
            $capitalization = $this->lock($capitalization);
            $this->assertStatus($capitalization, 'DRAFT');
            $this->assertPostedSource($capitalization);
            $this->assertLinesReady($capitalization);
            $capitalization->update(['status' => 'SUBMITTED', 'submitted_by' => $actor->id, 'submitted_at' => now(), 'updated_by' => $actor->id]);

            return $capitalization->fresh('lines');
        }, 3);
    }

    public function approve(AssetCapitalization $capitalization, User $actor): AssetCapitalization
    {
        return DB::transaction(function () use ($capitalization, $actor): AssetCapitalization {
            $capitalization = $this->lock($capitalization);
            $this->assertStatus($capitalization, 'SUBMITTED');
            $this->assertPostedSource($capitalization);
            $capitalization->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_by' => $actor->id]);

            return $capitalization->fresh('lines');
        }, 3);
    }

    public function void(AssetCapitalization $capitalization, string $reason, User $actor): AssetCapitalization
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['void_reason' => 'เหตุผลการยกเลิกต้องมี 10–500 ตัวอักษร']);
        }

        return DB::transaction(function () use ($capitalization, $reason, $actor): AssetCapitalization {
            $capitalization = $this->lock($capitalization);
            if (! in_array($capitalization->status, ['SUBMITTED', 'APPROVED'], true)) {
                throw ValidationException::withMessages(['status' => 'ยกเลิกได้เฉพาะใบรับรู้ที่รออนุมัติหรือพร้อมลงบัญชี']);
            }
            $capitalization->update(['status' => 'VOID', 'void_reason' => $reason, 'voided_by' => $actor->id, 'voided_at' => now(), 'updated_by' => $actor->id]);

            return $capitalization->fresh('lines');
        }, 3);
    }

    public function post(AssetCapitalization $capitalization, User $actor): AssetCapitalization
    {
        return DB::transaction(function () use ($capitalization, $actor): AssetCapitalization {
            $capitalization = $this->lock($capitalization);
            if ($capitalization->status === 'POSTED') {
                return $capitalization->fresh(['lines', 'journalEntry']);
            }
            $this->assertStatus($capitalization, 'APPROVED');
            $lines = $capitalization->lines()->orderBy('line_number')->lockForUpdate()->get();
            $this->assertLinesReady($capitalization, $lines);
            $this->lockAndAssertAllocationCeilings($capitalization, $lines);

            $journal = $capitalization->source_type === 'OPENING' ? null : $this->journals->postForBranchWithinTransaction([
                'source_type' => 'ASSET',
                'source_id' => (string) $capitalization->id,
                'source_reference' => $capitalization->document_number,
                'event_code' => 'asset.capitalization',
                'entry_date' => $capitalization->document_date->format('Y-m-d'),
                'document_date' => $capitalization->document_date->format('Y-m-d'),
                'description' => 'รับรู้สินทรัพย์ '.$capitalization->document_number,
                'lines' => $this->journalLines($lines),
            ], $capitalization->branch, null, $actor);

            foreach ($lines as $line) {
                $asset = Asset::query()->with('category')->lockForUpdate()->findOrFail($line->asset_id);
                $this->projectPostedLine($capitalization, $line, $asset, $journal?->id, $actor);
            }

            $capitalization->update([
                'status' => 'POSTED', 'journal_entry_id' => $journal?->id, 'posted_by' => $actor->id, 'posted_at' => now(), 'updated_by' => $actor->id,
            ]);

            return $capitalization->fresh(['lines', 'journalEntry']);
        }, 3);
    }

    public function reverse(AssetCapitalization $capitalization, string $reversalDate, string $reason, User $actor): AssetCapitalization
    {
        Validator::make(['reversal_date' => $reversalDate], ['reversal_date' => ['required', 'date_format:Y-m-d']])->validate();

        return DB::transaction(function () use ($capitalization, $reversalDate, $reason, $actor): AssetCapitalization {
            $capitalization = $this->lock($capitalization);
            if ($capitalization->status === 'REVERSED') {
                return $capitalization->fresh(['lines', 'reversalJournalEntry']);
            }
            $this->assertStatus($capitalization, 'POSTED');
            $reason = trim($reason);
            if (mb_strlen($reason) < 10 || mb_strlen($reason) > 500) {
                throw ValidationException::withMessages(['reason' => 'เหตุผลการกลับรายการต้องมี 10–500 ตัวอักษร']);
            }

            $journal = $capitalization->journalEntry
                ? $this->journals->reverseWithinTransaction($capitalization->journalEntry, [
                    'source_type' => 'ASSET', 'source_id' => "capitalization:{$capitalization->id}",
                    'reversal_date' => $reversalDate, 'reason' => $reason,
                ], $actor)
                : null;
            $lines = $capitalization->lines()->orderBy('line_number')->lockForUpdate()->get();
            foreach ($lines as $line) {
                $asset = Asset::query()->lockForUpdate()->findOrFail($line->asset_id);
                $this->projectReversedLine($capitalization, $line, $asset, $journal?->id, $reversalDate, $reason, $actor);
            }
            $capitalization->update([
                'status' => 'REVERSED', 'reversal_journal_entry_id' => $journal?->id, 'reversed_by' => $actor->id, 'reversed_at' => now(),
                'reversal_date' => $reversalDate, 'reversal_reason' => $reason, 'updated_by' => $actor->id,
            ]);

            return $capitalization->fresh(['lines', 'reversalJournalEntry']);
        }, 3);
    }

    private function validateDraft(array $attributes): array
    {
        $attributes['source_type'] = strtoupper(trim((string) ($attributes['source_type'] ?? '')));

        return Validator::make($attributes, [
            'document_number' => ['required', 'string', 'max:40'], 'document_date' => ['required', 'date_format:Y-m-d'],
            'source_type' => ['required', 'in:PURCHASE_DOCUMENT,OPENING,MANUAL_RECLASS'], 'source_id' => ['nullable', 'integer', 'min:1'],
            'is_manual_exception' => ['required', 'boolean'], 'manual_exception_reason' => ['nullable', 'string', 'min:10', 'max:500', 'required_if:is_manual_exception,1'],
            'description' => ['nullable', 'string', 'min:10', 'max:500', 'required_if:source_type,MANUAL_RECLASS'], 'lines' => ['required', 'array', 'min:1'],
            'lines.*.asset_id' => ['required', 'integer', 'min:1'], 'lines.*.capitalized_cost' => ['required', 'numeric', 'decimal:0,2', 'gt:0'],
            'lines.*.clearing_account_id' => ['nullable', 'integer', 'min:1'], 'lines.*.description' => ['nullable', 'string', 'max:500'],
            'lines.*.source_type' => ['nullable', 'string', 'max:30'], 'lines.*.source_id' => ['nullable', 'integer', 'min:1'], 'lines.*.source_line_id' => ['nullable', 'integer', 'min:1'],
        ])->validate();
    }

    private function assertDraftSource(Branch $branch, array $values): void
    {
        if ($values['source_type'] !== 'PURCHASE_DOCUMENT') {
            return;
        }
        $document = PurchaseDocument::query()->whereKey($values['source_id'] ?? null)->where('branch_id', $branch->id)
            ->where('document_type', 'INVOICE')->whereIn('status', ['APPROVED', 'POSTED'])->first();
        if (! $document) {
            throw ValidationException::withMessages(['source_id' => 'เลือกได้เฉพาะ Purchase Invoice ที่ Approved หรือ Posted ของสาขาปัจจุบัน']);
        }
    }

    /** @param iterable<AssetCapitalizationLine>|null $lines */
    private function assertLinesReady(AssetCapitalization $capitalization, ?iterable $lines = null): void
    {
        $lines ??= $capitalization->lines()->get();
        $seenAssets = [];
        foreach ($lines as $line) {
            if (isset($seenAssets[$line->asset_id])) {
                throw ValidationException::withMessages(['lines' => 'สินทรัพย์หนึ่งรายการรับรู้ต้นทุนได้เพียงหนึ่งบรรทัดต่อเอกสาร']);
            }
            $seenAssets[$line->asset_id] = true;
            $asset = Asset::query()->with('category')->find($line->asset_id);
            if (! $asset || $asset->branch_id !== $capitalization->branch_id || ! in_array($asset->status, ['DRAFT', 'REGISTERED'], true)) {
                throw ValidationException::withMessages(['lines' => 'สินทรัพย์ต้องเป็น Draft/Registered และอยู่ในสาขาเดียวกัน']);
            }
            if (! $line->asset_account_id || ($capitalization->source_type !== 'OPENING' && ! $line->clearing_account_id)) {
                throw ValidationException::withMessages(['lines' => 'ข้อมูลบัญชีที่ snapshot ไม่ครบ กรุณาสร้าง Draft ใหม่']);
            }
            if ($capitalization->source_type !== 'OPENING' && ! Account::query()->whereKey($line->clearing_account_id)->where('is_active', true)->where('is_postable', true)->whereNull('control_account_type')->exists()) {
                throw ValidationException::withMessages(['lines' => 'บัญชีเครดิตต้องเป็นบัญชีย่อยที่ลงรายการได้ ไม่ใช่บัญชีคุม กรุณาสร้าง Draft ใหม่']);
            }
            if ($this->decimal($line->capitalized_cost)->isLessThan($this->decimal($asset->category->capitalization_threshold))) {
                throw ValidationException::withMessages(['lines' => "ต้นทุน {$asset->asset_number} ต่ำกว่าเกณฑ์ของหมวดสินทรัพย์"]);
            }
        }
    }

    /** @param iterable<AssetCapitalizationLine> $lines @return array<int, PurchaseDocumentLine> */
    private function lockAndAssertAllocationCeilings(AssetCapitalization $capitalization, iterable $lines): array
    {
        if ($capitalization->source_type !== 'PURCHASE_DOCUMENT') {
            return [];
        }
        $document = PurchaseDocument::query()->whereKey($capitalization->source_id)->where('branch_id', $capitalization->branch_id)->lockForUpdate()->first();
        if (! $document || $document->document_type !== 'INVOICE' || $document->status !== 'POSTED') {
            throw ValidationException::withMessages(['source_id' => 'Post รับรู้สินทรัพย์ได้เมื่อ Purchase Invoice เป็น Posted เท่านั้น']);
        }
        $sourceLines = [];
        foreach (collect($lines)->sortBy(fn (AssetCapitalizationLine $line) => (int) $line->source_line_id) as $line) {
            if ($line->source_type !== 'PURCHASE_DOCUMENT' || (int) $line->source_id !== (int) $document->id || ! $line->source_line_id) {
                throw ValidationException::withMessages(['lines' => 'บรรทัดรับรู้ต้องอ้าง Purchase Invoice ต้นทางเดียวกับเอกสาร']);
            }
            $source = PurchaseDocumentLine::query()->whereKey($line->source_line_id)->where('purchase_document_id', $document->id)->lockForUpdate()->first();
            if (! $source) {
                throw ValidationException::withMessages(['lines' => 'ไม่พบบรรทัด Purchase Invoice ต้นทาง']);
            }
            $alreadyPosted = AssetCapitalizationLine::query()->join('asset_capitalizations', 'asset_capitalizations.id', '=', 'asset_capitalization_lines.asset_capitalization_id')
                ->where('asset_capitalizations.status', 'POSTED')->where('asset_capitalization_lines.source_type', 'PURCHASE_DOCUMENT')
                ->where('asset_capitalization_lines.source_id', $document->id)->where('asset_capitalization_lines.source_line_id', $source->id)
                ->sum('asset_capitalization_lines.capitalized_cost');
            $requested = $this->decimal($line->capitalized_cost);
            if ($this->decimal($alreadyPosted)->plus($requested)->isGreaterThan($this->decimal($source->net_amount))) {
                throw ValidationException::withMessages(['lines' => "ยอดรับรู้ของบรรทัด Purchase Invoice #{$source->line_number} เกินยอดสุทธิที่ยังเหลือ"]);
            }
            $sourceLines[$line->id] = $source;
        }

        return $sourceLines;
    }

    /** @param array<int, array<string, mixed>> $lines */
    private function createLines(AssetCapitalization $capitalization, Branch $branch, array $lines, User $actor): void
    {
        foreach ($lines as $index => $values) {
            $asset = Asset::query()->with('category')->whereKey($values['asset_id'])->where('branch_id', $branch->id)->first();
            if (! $asset || ! $asset->category->asset_account_id) {
                throw ValidationException::withMessages(["lines.{$index}.asset_id" => 'สินทรัพย์หรือบัญชีสินทรัพย์ของหมวดไม่พร้อมใช้งาน']);
            }
            $source = null;
            if ($capitalization->source_type === 'PURCHASE_DOCUMENT') {
                $source = PurchaseDocumentLine::query()->with('item')->whereKey($values['source_line_id'] ?? null)->where('purchase_document_id', $capitalization->source_id)->first();
                $isEligible = $source?->item?->is_asset_capitalizable && $source->item->default_asset_category_id;
                if (! $source || (! $isEligible && (! $capitalization->is_manual_exception || ! $actor->hasPermission('asset.capitalizations.exception')))) {
                    throw ValidationException::withMessages(["lines.{$index}.source_line_id" => 'เลือกได้เฉพาะรายการซื้อที่ Item Master กำหนดให้รับรู้เป็นสินทรัพย์']);
                }
                if ($isEligible && (int) $asset->asset_category_id !== (int) $source->item->default_asset_category_id) {
                    throw ValidationException::withMessages(["lines.{$index}.asset_id" => 'หมวดของ Asset ต้องตรงกับหมวดสินทรัพย์เริ่มต้นของ Item']);
                }
            }
            $clearingAccountId = $values['clearing_account_id'] ?? $source?->account_id;
            if ($capitalization->source_type !== 'OPENING' && ! Account::query()->whereKey($clearingAccountId)->where('is_active', true)->where('is_postable', true)->whereNull('control_account_type')->exists()) {
                throw ValidationException::withMessages(["lines.{$index}.clearing_account_id" => 'บัญชีเครดิตต้องเป็นบัญชีย่อยที่ลงรายการได้ ไม่ใช่บัญชีคุม']);
            }
            $capitalization->lines()->create([
                'asset_id' => $asset->id, 'line_number' => $index + 1, 'source_type' => $capitalization->source_type,
                'source_id' => $capitalization->source_id, 'source_line_id' => $source?->id ?? ($values['source_line_id'] ?? null),
                'capitalized_cost' => $this->decimal($values['capitalized_cost'])->__toString(), 'asset_account_id' => $asset->category->asset_account_id,
                'clearing_account_id' => $clearingAccountId,
                'book_profile_snapshot' => $this->bookProfile($asset), 'tax_profile_snapshot' => $this->taxProfile($asset),
                'description' => trim((string) ($values['description'] ?? '')) ?: null,
            ]);
        }
    }

    /** @param iterable<AssetCapitalizationLine> $lines */
    private function journalLines(iterable $lines): array
    {
        return collect($lines)->flatMap(fn (AssetCapitalizationLine $line) => [[
            'account_id' => $line->asset_account_id, 'subledger_type' => 'ASSET', 'subledger_id' => (string) $line->asset_id, 'debit' => $line->capitalized_cost, 'credit' => 0, 'description' => $line->description,
        ], [
            'account_id' => $line->clearing_account_id, 'debit' => 0, 'credit' => $line->capitalized_cost, 'description' => $line->description,
        ]])->all();
    }

    private function projectPostedLine(AssetCapitalization $capitalization, AssetCapitalizationLine $line, Asset $asset, ?int $journalId, User $actor): void
    {
        $cost = $this->decimal($line->capitalized_cost);
        $event = AssetValueEvent::query()->firstOrCreate(['idempotency_key' => $this->eventKey($capitalization, $line)], [
            'asset_id' => $asset->id, 'branch_id' => $asset->branch_id, 'event_date' => $capitalization->document_date,
            'event_type' => $capitalization->source_type === 'OPENING' ? 'OPENING' : 'CAPITALIZATION', 'cost_delta' => $cost->__toString(),
            'source_type' => 'ASSET_CAPITALIZATION', 'source_id' => $capitalization->id, 'source_line_id' => $line->id, 'journal_entry_id' => $journalId, 'created_by' => $actor->id,
        ]);
        if (! $event->wasRecentlyCreated) {
            return;
        }
        $before = $asset->only(['status', 'original_cost', 'book_cost', 'book_value']);
        $asset->update([
            // A Draft carries an estimate only; its first capitalization establishes the accounting value.
            'original_cost' => $cost->__toString(), 'book_cost' => $cost->__toString(),
            'book_value' => $cost->__toString(), 'status' => 'ACTIVE', 'source_type' => $capitalization->source_type,
            'source_id' => $capitalization->source_id, 'source_line_id' => $line->source_line_id, 'updated_by' => $actor->id,
        ]);
        $this->history($asset, $capitalization, $actor, 'CAPITALIZED', $before, $asset->only(array_keys($before)));
    }

    private function projectReversedLine(AssetCapitalization $capitalization, AssetCapitalizationLine $line, Asset $asset, ?int $journalId, string $date, string $reason, User $actor): void
    {
        $event = AssetValueEvent::query()->where('idempotency_key', $this->eventKey($capitalization, $line))->lockForUpdate()->firstOrFail();
        $reversal = AssetValueEvent::query()->firstOrCreate(['idempotency_key' => $this->reversalEventKey($capitalization, $line)], [
            'asset_id' => $asset->id, 'branch_id' => $asset->branch_id, 'event_date' => $date, 'event_type' => 'REVERSAL',
            'cost_delta' => $this->decimal($event->cost_delta)->negated()->__toString(), 'depreciation_delta' => $this->decimal($event->depreciation_delta)->negated()->__toString(),
            'impairment_delta' => $this->decimal($event->impairment_delta)->negated()->__toString(), 'source_type' => 'ASSET_CAPITALIZATION',
            'source_id' => $capitalization->id, 'source_line_id' => $line->id, 'journal_entry_id' => $journalId, 'reversal_of_event_id' => $event->id, 'created_by' => $actor->id,
        ]);
        if (! $reversal->wasRecentlyCreated) {
            return;
        }
        $before = $asset->only(['status', 'original_cost', 'book_cost', 'book_value']);
        $capitalizationHistory = AssetHistory::query()->where('asset_id', $asset->id)->where('source_type', 'ASSET_CAPITALIZATION')
            ->where('source_id', $capitalization->id)->where('event_type', 'CAPITALIZED')->latest('id')->first();
        $restore = $capitalizationHistory?->old_values ?? [];
        $asset->update([
            'original_cost' => $this->decimal($restore['original_cost'] ?? 0)->__toString(), 'book_cost' => $this->decimal($restore['book_cost'] ?? 0)->__toString(),
            'book_value' => $this->decimal($restore['book_value'] ?? 0)->__toString(), 'status' => $capitalizationHistory?->old_status ?? 'REGISTERED', 'updated_by' => $actor->id,
        ]);
        $this->history($asset, $capitalization, $actor, 'CAPITALIZATION_REVERSED', $before, $asset->only(array_keys($before)), $reason);
    }

    private function assertPostedSource(AssetCapitalization $capitalization): void
    {
        if ($capitalization->source_type === 'PURCHASE_DOCUMENT') {
            $document = PurchaseDocument::query()->whereKey($capitalization->source_id)->where('branch_id', $capitalization->branch_id)
                ->where('document_type', 'INVOICE')->where('status', 'POSTED')->first();
            if (! $document) {
                throw ValidationException::withMessages(['source_id' => 'Submit/Approve ได้เมื่อ Purchase Invoice เป็น Posted เท่านั้น']);
            }
        }
    }

    private function lock(AssetCapitalization $capitalization): AssetCapitalization
    {
        return AssetCapitalization::query()->with('branch', 'journalEntry')->lockForUpdate()->findOrFail($capitalization->id);
    }

    private function assertStatus(AssetCapitalization $capitalization, string $status): void
    {
        if ($capitalization->status !== $status) {
            throw ValidationException::withMessages(['status' => "เอกสารต้องอยู่สถานะ {$status}"]);
        }
    }

    private function bookProfile(Asset $asset): array
    {
        $category = $asset->category;

        return ['method' => $category->book_method, 'useful_life_months' => $category->book_useful_life_months, 'residual_value_percent' => $category->book_residual_value_percent];
    }

    private function taxProfile(Asset $asset): array
    {
        $category = $asset->category;

        return ['method' => $category->tax_method, 'useful_life_months' => $category->tax_useful_life_months, 'rate_percent' => $category->tax_rate_percent, 'cost_cap' => $category->tax_cost_cap];
    }

    private function history(Asset $asset, AssetCapitalization $capitalization, User $actor, string $event, array $old, array $new, ?string $reason = null): void
    {
        AssetHistory::query()->create([
            'asset_id' => $asset->id, 'event_type' => $event, 'occurred_at' => now(), 'source_type' => 'ASSET_CAPITALIZATION',
            'source_id' => $capitalization->id, 'source_document_number' => $capitalization->document_number, 'actor_id' => $actor->id,
            'reason' => $reason, 'old_status' => $old['status'] ?? null, 'new_status' => $new['status'] ?? null, 'old_values' => $old, 'new_values' => $new,
        ]);
    }

    private function decimal(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) $value)->toScale(2, RoundingMode::HALF_UP);
    }

    private function eventKey(AssetCapitalization $capitalization, AssetCapitalizationLine $line): string
    {
        return hash('sha256', "asset-capitalization:{$capitalization->id}:{$line->id}");
    }

    private function reversalEventKey(AssetCapitalization $capitalization, AssetCapitalizationLine $line): string
    {
        return hash('sha256', "asset-capitalization-reversal:{$capitalization->id}:{$line->id}");
    }
}
