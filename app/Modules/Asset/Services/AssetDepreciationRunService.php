<?php

namespace App\Modules\Asset\Services;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetDepreciationBook;
use App\Modules\Asset\Models\AssetDepreciationLine;
use App\Modules\Asset\Models\AssetDepreciationPolicyChange;
use App\Modules\Asset\Models\AssetDepreciationRun;
use App\Modules\Asset\Models\AssetDepreciationRunException;
use App\Modules\Asset\Models\AssetHistory;
use App\Modules\Asset\Models\AssetValueEvent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Owns immutable depreciation snapshots; Accounting remains the only GL writer. */
final class AssetDepreciationRunService
{
    public function __construct(
        private readonly DepreciationPreviewCalculator $calculator,
        private readonly JournalPostingService $journals,
    ) {}

    public function createDraft(Branch $branch, array $attributes, User $actor): AssetDepreciationRun
    {
        $values = $this->validateDraft($attributes);

        return DB::transaction(function () use ($branch, $values, $actor): AssetDepreciationRun {
            $period = $this->period($values['fiscal_period_id'], $values['run_through_date']);
            $existing = AssetDepreciationRun::query()->where('branch_id', $branch->id)->where('fiscal_period_id', $period->id)
                ->where('book_type', $values['book_type'])->whereNotIn('status', ['REVERSED', 'VOID', 'FAILED'])->lockForUpdate()->first();
            if ($existing) {
                $existingProration = $existing->lines()->value('calculation_input_snapshot->proration');
                if ($existing->run_through_date->isSameDay($values['run_through_date']) && $existingProration === $values['proration']) {
                    return $existing->fresh('lines');
                }

                throw ValidationException::withMessages(['book_type' => 'สาขาและงวดนี้มีชุดค่าเสื่อมประเภทนี้อยู่แล้ว หากต้องคำนวณใหม่ให้ยกเลิกชุดเดิมก่อน']);
            }

            $run = AssetDepreciationRun::query()->create([
                'document_number' => $values['document_number'], 'branch_id' => $branch->id, 'fiscal_period_id' => $period->id,
                'book_type' => $values['book_type'], 'run_through_date' => $values['run_through_date'], 'status' => 'CALCULATING',
                'progress_percent' => 0, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $this->calculateLines($run, $values['proration'], $values['asset_ids'], $values['exclusion_reasons'] ?? [], $actor);

            return $run->fresh('lines');
        }, 3);
    }

    public function submit(AssetDepreciationRun $run, User $actor): AssetDepreciationRun
    {
        return DB::transaction(function () use ($run, $actor): AssetDepreciationRun {
            $run = $this->lock($run);
            $this->assertStatus($run, 'DRAFT');
            $this->assertReady($run);
            $run->update(['status' => 'SUBMITTED', 'submitted_by' => $actor->id, 'submitted_at' => now(), 'updated_by' => $actor->id]);

            return $run->fresh('lines');
        }, 3);
    }

    public function approve(AssetDepreciationRun $run, User $actor): AssetDepreciationRun
    {
        return DB::transaction(function () use ($run, $actor): AssetDepreciationRun {
            $run = $this->lock($run);
            $this->assertStatus($run, 'SUBMITTED');
            $this->assertReady($run);
            $run->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_by' => $actor->id]);

            return $run->fresh('lines');
        }, 3);
    }

    public function cancel(AssetDepreciationRun $run, string $reason, User $actor): AssetDepreciationRun
    {
        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'min:10', 'max:500']])->validate();

        return DB::transaction(function () use ($run, $reason, $actor): AssetDepreciationRun {
            $run = $this->lock($run);
            $this->assertStatus($run, 'SUBMITTED');
            $run->update(['status' => 'VOID', 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => trim($reason), 'updated_by' => $actor->id]);

            return $run->fresh('lines');
        }, 3);
    }

    public function post(AssetDepreciationRun $run, User $actor): AssetDepreciationRun
    {
        return DB::transaction(function () use ($run, $actor): AssetDepreciationRun {
            $run = $this->lock($run);
            if ($run->status === 'POSTED') {
                return $run->fresh(['lines', 'journalEntry']);
            }
            $this->assertStatus($run, 'APPROVED');
            $this->assertReady($run);
            $lines = $run->lines()->orderBy('line_number')->lockForUpdate()->get();
            $books = AssetDepreciationBook::query()->whereKey($lines->pluck('asset_depreciation_book_id'))->lockForUpdate()->get()->keyBy('id');

            $journal = null;
            if ($run->book_type === 'BOOK' && $lines->contains(fn (AssetDepreciationLine $line) => $this->amount($line->period_depreciation)->plus($this->amount($line->catch_up_adjustment))->isGreaterThan(0))) {
                $journal = $this->journals->postForBranchWithinTransaction([
                    'source_type' => 'ASSET', 'source_id' => (string) $run->id, 'source_reference' => $run->document_number,
                    'event_code' => 'asset.depreciation', 'entry_date' => $run->run_through_date->toDateString(),
                    'document_date' => $run->run_through_date->toDateString(), 'description' => 'ค่าเสื่อมราคา '.$run->document_number,
                    'lines' => $this->journalLines($lines),
                ], $run->branch, null, $actor)->load('lines');
            }

            foreach ($lines as $line) {
                $book = $books->get($line->asset_depreciation_book_id);
                if (! $book) {
                    throw ValidationException::withMessages(['lines' => 'ไม่พบข้อมูล Book ที่คำนวณไว้ กรุณาสร้างชุดค่าเสื่อมใหม่']);
                }
                $this->projectPostedLine($run, $line, $book, $journal, $actor);
            }
            $run->update(['status' => 'POSTED', 'journal_entry_id' => $journal?->id, 'posted_by' => $actor->id, 'posted_at' => now(), 'updated_by' => $actor->id]);

            return $run->fresh(['lines', 'journalEntry']);
        }, 3);
    }

    public function reverse(AssetDepreciationRun $run, string $reversalDate, string $reason, User $actor): AssetDepreciationRun
    {
        Validator::make(['reversal_date' => $reversalDate, 'reason' => $reason], [
            'reversal_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:10', 'max:500'],
        ])->validate();

        return DB::transaction(function () use ($run, $reversalDate, $reason, $actor): AssetDepreciationRun {
            $run = $this->lock($run);
            if ($run->status === 'REVERSED') {
                return $run->fresh(['lines', 'reversalJournalEntry']);
            }
            $this->assertStatus($run, 'POSTED');
            $this->periodForDate($reversalDate);
            $reason = trim($reason);
            $journal = $run->journalEntry ? $this->journals->reverseWithinTransaction($run->journalEntry, [
                'source_type' => 'ASSET', 'source_id' => "depreciation:{$run->id}", 'reversal_date' => $reversalDate, 'reason' => $reason,
            ], $actor) : null;
            $lines = $run->lines()->orderBy('line_number')->lockForUpdate()->get();
            $books = AssetDepreciationBook::query()->whereKey($lines->pluck('asset_depreciation_book_id'))->lockForUpdate()->get()->keyBy('id');
            foreach ($lines as $line) {
                $book = $books->get($line->asset_depreciation_book_id);
                if ($book) {
                    $this->projectReversedLine($run, $line, $book, $journal?->id, $reversalDate, $reason, $actor);
                }
            }
            $run->update([
                'status' => 'REVERSED', 'reversal_journal_entry_id' => $journal?->id, 'reversed_by' => $actor->id, 'reversed_at' => now(),
                'reversal_date' => $reversalDate, 'reversal_reason' => $reason, 'updated_by' => $actor->id,
            ]);

            return $run->fresh(['lines', 'reversalJournalEntry']);
        }, 3);
    }

    private function calculateLines(AssetDepreciationRun $run, string $proration, array $selectedIds, array $exclusionReasons, User $actor): void
    {
        $eligibleAssets = Asset::query()->with(['category', 'depreciationBooks' => fn ($query) => $query->where('book_type', $run->book_type)->where('is_active', true)])
            ->where('branch_id', $run->branch_id)->where('status', 'ACTIVE')->where('is_depreciation_suspended', false)->lockForUpdate()->orderBy('id')->get();
        $eligibleAssets = $eligibleAssets->filter(fn (Asset $asset) => $asset->depreciationBooks->isNotEmpty())->values();
        $selectedIds = array_map('intval', $selectedIds);
        if (array_diff($selectedIds, $eligibleAssets->pluck('id')->all())) {
            throw ValidationException::withMessages(['asset_ids' => 'พบสินทรัพย์ที่ไม่เข้าเงื่อนไขสำหรับชุดค่าเสื่อมนี้']);
        }
        $assets = $eligibleAssets->whereIn('id', $selectedIds)->values();
        $excludedAssets = $eligibleAssets->whereNotIn('id', $selectedIds);
        foreach ($excludedAssets as $asset) {
            $reason = trim((string) ($exclusionReasons[$asset->id] ?? ''));
            if (mb_strlen($reason) < 10) {
                throw ValidationException::withMessages(["exclusion_reasons.{$asset->id}" => "โปรดระบุเหตุผลการยกเว้น {$asset->asset_number} อย่างน้อย 10 ตัวอักษร"]);
            }
            AssetDepreciationRunException::query()->create(['asset_depreciation_run_id' => $run->id, 'asset_id' => $asset->id, 'asset_number' => $asset->asset_number, 'asset_name' => $asset->name, 'reason' => $reason, 'created_by' => $actor->id]);
        }
        $payload = [];
        foreach ($assets as $asset) {
            foreach ($asset->depreciationBooks as $book) {
                $opening = $this->amount($book->accumulated_depreciation);
                $policy = AssetDepreciationPolicyChange::query()->where('asset_depreciation_book_id', $book->id)->where('status', 'APPROVED')->whereDate('effective_date', '<=', $run->run_through_date)->latest('effective_date')->first();
                if ($policy) {
                    $profile = $policy->profile_snapshot;
                    $baseline = $profile['approval_baseline'];
                    $policyBook = new AssetDepreciationBook(['method' => $profile['requested_profile']['method'], 'depreciable_cost' => $baseline['remaining_book_value'], 'residual_value' => $profile['requested_profile']['residual_value'], 'useful_life_months' => $profile['requested_profile']['useful_life_months'], 'start_date' => $policy->effective_date, 'accumulated_depreciation' => '0.00']);
                    $calculation = $this->calculator->calculateThrough($policyBook, $proration, $run->run_through_date, '0.00');
                    $policyPosted = $opening->minus($this->amount($baseline['accumulated_depreciation']));
                    $due = $this->positive($this->amount($calculation['target_accumulated_depreciation'])->minus($policyPosted));
                    $beforePeriod = $this->calculator->calculateThrough($policyBook, $proration, $run->fiscalPeriod->start_date->subDay(), '0.00');
                    $catchUp = $this->positive($this->amount($beforePeriod['target_accumulated_depreciation'])->minus($policyPosted));
                    $calculation['policy_change_id'] = $policy->id;
                } else {
                    $calculation = $this->calculator->calculateThrough($book, $proration, $run->run_through_date, $book->accumulated_depreciation);
                    $beforePeriod = $this->calculator->calculateThrough($book, $proration, $run->fiscalPeriod->start_date->subDay(), $book->accumulated_depreciation);
                    $catchUp = $this->amount($beforePeriod['depreciation_due']);
                    $due = $this->amount($calculation['depreciation_due']);
                }
                $period = $due->minus($catchUp);
                $snapshot = [
                    'method' => $book->method, 'proration' => $proration, 'depreciable_cost' => (string) $book->depreciable_cost,
                    'residual_value' => (string) $book->residual_value, 'useful_life_months' => $book->useful_life_months,
                    'start_date' => $book->start_date?->toDateString(), 'end_date' => $book->end_date?->toDateString(),
                    'accumulated_depreciation' => (string) $book->accumulated_depreciation,
                    'last_depreciation_date' => $book->last_depreciation_date?->toDateString(),
                    'policy_change_id' => $policy?->id,
                    'depreciation_expense_account_id' => $asset->category?->depreciation_expense_account_id,
                    'accumulated_depreciation_account_id' => $asset->category?->accumulated_depreciation_account_id,
                ];
                $payload[] = compact('asset', 'book', 'calculation', 'opening', 'catchUp', 'period', 'snapshot');
            }
        }
        foreach ($payload as $index => $row) {
            /** @var Asset $asset */
            $asset = $row['asset'];
            $period = $row['period'];
            $catchUp = $row['catchUp'];
            $opening = $row['opening'];
            $run->lines()->create([
                'asset_id' => $asset->id, 'asset_depreciation_book_id' => $row['book']->id, 'line_number' => $index + 1,
                'asset_number' => $asset->asset_number, 'category_code' => $asset->category?->code, 'category_name' => $asset->category?->name,
                'opening_cost' => $asset->book_cost, 'opening_accumulated_depreciation' => $opening->__toString(), 'opening_accumulated_impairment' => $asset->book_accumulated_impairment,
                'period_depreciation' => $period->__toString(), 'catch_up_adjustment' => $catchUp->__toString(), 'closing_cost' => $asset->book_cost,
                'closing_accumulated_depreciation' => $opening->plus($period)->plus($catchUp)->__toString(), 'closing_accumulated_impairment' => $asset->book_accumulated_impairment,
                'closing_book_value' => $this->amount($asset->book_value)->minus($period)->minus($catchUp)->__toString(), 'calculation_input_snapshot' => $row['snapshot'],
                'calculation_explanation' => $row['calculation'],
            ]);
        }
        $hash = hash('sha256', json_encode(collect($payload)->map(fn (array $row) => [
            $row['asset']->id, $row['book']->id, $row['period']->__toString(), $row['catchUp']->__toString(), $row['snapshot'],
        ])->all(), JSON_THROW_ON_ERROR));
        $total = $this->sum($payload, 'period');
        $catchUpTotal = $this->sum($payload, 'catchUp');
        $run->update([
            'status' => 'DRAFT', 'asset_count' => count($payload), 'total_depreciation' => $total->__toString(),
            'total_catch_up_adjustment' => $catchUpTotal->__toString(), 'calculation_hash' => $hash,
            'progress_percent' => 100, 'updated_by' => $actor->id,
        ]);
    }

    /** @param iterable<AssetDepreciationLine> $lines */
    private function journalLines(iterable $lines): array
    {
        $rows = [];
        foreach ($lines as $line) {
            $amount = $this->amount($line->period_depreciation)->plus($this->amount($line->catch_up_adjustment));
            if ($amount->isZero()) {
                continue;
            }
            $snapshot = $line->calculation_input_snapshot;
            foreach ([['depreciation_expense_account_id', 'debit'], ['accumulated_depreciation_account_id', 'credit']] as [$field, $side]) {
                $accountId = $snapshot[$field] ?? null;
                if (! $accountId) {
                    throw ValidationException::withMessages(['lines' => 'หมวดสินทรัพย์ไม่มีบัญชีค่าเสื่อมที่ snapshot ไว้ กรุณาสร้างชุดค่าเสื่อมใหม่']);
                }
                $account = Account::query()->find($accountId);
                if (! $account || ! $account->is_active || ! $account->is_postable) {
                    throw ValidationException::withMessages(['lines' => 'บัญชีค่าเสื่อมที่ snapshot ไว้ไม่พร้อมลงรายการ']);
                }
                $isControl = $account->control_account_type !== null;
                $key = $accountId.'|'.$side.'|'.($isControl ? $line->asset_id : '');
                $rows[$key] ??= ['account_id' => $accountId, 'subledger_type' => $isControl ? 'ASSET' : null,
                    'subledger_id' => $isControl ? (string) $line->asset_id : null, 'debit' => '0.00', 'credit' => '0.00', 'description' => 'ค่าเสื่อม '.$line->asset_number];
                $rows[$key][$side] = $this->amount($rows[$key][$side])->plus($amount)->__toString();
            }
        }

        return array_values($rows);
    }

    private function projectPostedLine(AssetDepreciationRun $run, AssetDepreciationLine $line, AssetDepreciationBook $book, mixed $journal, User $actor): void
    {
        $amount = $this->amount($line->period_depreciation)->plus($this->amount($line->catch_up_adjustment));
        if ($amount->isZero()) {
            return;
        }
        $book->update(['accumulated_depreciation' => $line->closing_accumulated_depreciation, 'last_depreciation_date' => $run->run_through_date, 'updated_by' => $actor->id]);
        if ($run->book_type !== 'BOOK') {
            return;
        }
        $asset = Asset::query()->lockForUpdate()->findOrFail($line->asset_id);
        $event = AssetValueEvent::query()->firstOrCreate(['idempotency_key' => $this->eventKey($run, $line)], [
            'asset_id' => $asset->id, 'branch_id' => $asset->branch_id, 'event_date' => $run->run_through_date, 'event_type' => 'DEPRECIATION',
            'depreciation_delta' => $amount->__toString(), 'source_type' => 'ASSET_DEPRECIATION', 'source_id' => $run->id, 'source_line_id' => $line->id,
            'journal_entry_id' => $journal?->id, 'created_by' => $actor->id,
        ]);
        if (! $event->wasRecentlyCreated) {
            return;
        }
        $before = $asset->only(['book_accumulated_depreciation', 'book_value']);
        $asset->update(['book_accumulated_depreciation' => $line->closing_accumulated_depreciation, 'book_value' => $line->closing_book_value, 'updated_by' => $actor->id]);
        AssetHistory::query()->create([
            'asset_id' => $asset->id, 'event_type' => 'DEPRECIATED', 'occurred_at' => now(), 'source_type' => 'ASSET_DEPRECIATION',
            'source_id' => $run->id, 'source_document_number' => $run->document_number, 'actor_id' => $actor->id,
            'old_values' => $before, 'new_values' => $asset->only(array_keys($before)),
        ]);
    }

    private function projectReversedLine(AssetDepreciationRun $run, AssetDepreciationLine $line, AssetDepreciationBook $book, ?int $journalId, string $date, string $reason, User $actor): void
    {
        $amount = $this->amount($line->period_depreciation)->plus($this->amount($line->catch_up_adjustment));
        if ($amount->isZero()) {
            return;
        }
        $book->update(['accumulated_depreciation' => $line->opening_accumulated_depreciation,
            'last_depreciation_date' => $line->calculation_input_snapshot['last_depreciation_date'] ?? null, 'updated_by' => $actor->id]);
        if ($run->book_type !== 'BOOK') {
            return;
        }
        $asset = Asset::query()->lockForUpdate()->findOrFail($line->asset_id);
        $event = AssetValueEvent::query()->where('idempotency_key', $this->eventKey($run, $line))->lockForUpdate()->firstOrFail();
        $reversal = AssetValueEvent::query()->firstOrCreate(['idempotency_key' => $this->reversalEventKey($run, $line)], [
            'asset_id' => $asset->id, 'branch_id' => $asset->branch_id, 'event_date' => $date, 'event_type' => 'REVERSAL',
            'depreciation_delta' => $amount->negated()->__toString(), 'source_type' => 'ASSET_DEPRECIATION', 'source_id' => $run->id,
            'source_line_id' => $line->id, 'journal_entry_id' => $journalId, 'reversal_of_event_id' => $event->id, 'created_by' => $actor->id,
        ]);
        if (! $reversal->wasRecentlyCreated) {
            return;
        }
        $before = $asset->only(['book_accumulated_depreciation', 'book_value']);
        $asset->update(['book_accumulated_depreciation' => $line->opening_accumulated_depreciation, 'book_value' => $this->amount($line->opening_cost)->minus($this->amount($line->opening_accumulated_depreciation))->minus($this->amount($line->opening_accumulated_impairment))->__toString(), 'updated_by' => $actor->id]);
        AssetHistory::query()->create([
            'asset_id' => $asset->id, 'event_type' => 'DEPRECIATION_REVERSED', 'occurred_at' => now(), 'source_type' => 'ASSET_DEPRECIATION',
            'source_id' => $run->id, 'source_document_number' => $run->document_number, 'actor_id' => $actor->id, 'reason' => $reason,
            'old_values' => $before, 'new_values' => $asset->only(array_keys($before)),
        ]);
    }

    private function validateDraft(array $attributes): array
    {
        return Validator::make($attributes, [
            'document_number' => ['required', 'string', 'max:40'], 'fiscal_period_id' => ['required', 'integer', 'exists:fiscal_periods,id'],
            'book_type' => ['required', 'in:BOOK,TAX'], 'proration' => ['required', 'in:FULL_MONTH,DAILY'],
            'run_through_date' => ['required', 'date_format:Y-m-d'],
            'asset_ids' => ['required', 'array', 'min:1'], 'asset_ids.*' => ['integer', 'distinct', 'exists:assets,id'],
            'exclusion_reasons' => ['nullable', 'array'], 'exclusion_reasons.*' => ['nullable', 'string', 'max:500'],
        ])->validate();
    }

    private function period(int $id, string $date): FiscalPeriod
    {
        $period = FiscalPeriod::query()->lockForUpdate()->find($id);
        if (! $period || $period->status !== 'OPEN' || ! CarbonImmutable::parse($date)->betweenIncluded($period->start_date, $period->end_date)) {
            throw ValidationException::withMessages(['fiscal_period_id' => 'วันที่คำนวณต้องอยู่ในงวดบัญชีที่เปิดอยู่']);
        }

        return $period;
    }

    private function periodForDate(string $date): void
    {
        if (! FiscalPeriod::query()->where('status', 'OPEN')->whereDate('start_date', '<=', $date)->whereDate('end_date', '>=', $date)->lockForUpdate()->exists()) {
            throw ValidationException::withMessages(['reversal_date' => 'วันที่ยกเลิกต้องอยู่ในงวดบัญชีที่เปิดอยู่']);
        }
    }

    private function lock(AssetDepreciationRun $run): AssetDepreciationRun
    {
        return AssetDepreciationRun::query()->with(['branch', 'fiscalPeriod', 'journalEntry'])->lockForUpdate()->findOrFail($run->id);
    }

    private function assertReady(AssetDepreciationRun $run): void
    {
        $this->period($run->fiscal_period_id, $run->run_through_date->toDateString());
        if ($run->progress_percent != 100 || ! $run->calculation_hash || ! $run->lines()->exists()) {
            throw ValidationException::withMessages(['run' => 'ชุดค่าเสื่อมคำนวณไม่สมบูรณ์ กรุณาสร้างใหม่']);
        }
    }

    private function assertStatus(AssetDepreciationRun $run, string $status): void
    {
        if ($run->status !== $status) {
            throw ValidationException::withMessages(['status' => "เอกสารต้องอยู่สถานะ {$status}"]);
        }
    }

    private function amount(mixed $value): BigDecimal
    {
        return BigDecimal::of((string) $value)->toScale(2, RoundingMode::HALF_UP);
    }

    private function positive(BigDecimal $value): BigDecimal
    {
        return $value->isNegative() ? $this->amount(0) : $value;
    }

    /** @param array<int, array<string, mixed>> $rows */
    private function sum(array $rows, string $field): BigDecimal
    {
        return array_reduce($rows, fn (BigDecimal $total, array $row) => $total->plus($row[$field]), $this->amount(0));
    }

    private function eventKey(AssetDepreciationRun $run, AssetDepreciationLine $line): string
    {
        return hash('sha256', "asset-depreciation:{$run->id}:{$line->id}");
    }

    private function reversalEventKey(AssetDepreciationRun $run, AssetDepreciationLine $line): string
    {
        return hash('sha256', "asset-depreciation-reversal:{$run->id}:{$line->id}");
    }
}
