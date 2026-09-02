<?php

namespace App\Modules\Asset\Services;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetDepreciationRun;
use App\Modules\Asset\Models\AssetDisposal;
use App\Modules\Asset\Models\AssetValueEvent;
use App\Modules\Asset\Support\AssetPostingAccountResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AssetDisposalService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly ?AssetPostingAccountResolver $accounts = null,
    ) {}

    public function createDraft(Branch $branch, array $attributes, User $actor): AssetDisposal
    {
        $values = Validator::make($attributes, [
            'document_number' => ['required', 'string', 'max:40'], 'disposal_type' => ['required', 'in:SALE,WRITE_OFF'],
            'disposal_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:10', 'max:500'],
            'proceeds' => ['nullable', 'numeric', 'min:0'], 'proceeds_reference' => ['nullable', 'string', 'max:100'],
            'count_reference' => ['nullable', 'string', 'max:100'], 'investigation_reference' => ['nullable', 'string', 'max:100'],
            'override_reason' => ['nullable', 'string', 'min:10', 'max:500'], 'asset_ids' => ['required', 'array', 'min:1'], 'asset_ids.*' => ['integer', 'distinct', 'min:1'],
        ])->validate();
        if ($values['disposal_type'] === 'WRITE_OFF' && (float) ($values['proceeds'] ?? 0) > 0) {
            throw ValidationException::withMessages(['proceeds' => 'การตัดจำหน่ายไม่มีเงินรับ']);
        }
        if ($values['disposal_type'] === 'WRITE_OFF' && empty($values['count_reference']) && empty($values['investigation_reference']) && empty($values['override_reason'])) {
            throw ValidationException::withMessages(['evidence' => 'การตัดจำหน่ายต้องมีเลขที่ตรวจนับ เลขที่สอบสวน หรือเหตุผล Override']);
        }
        if ($values['disposal_type'] === 'SALE' && (float) ($values['proceeds'] ?? 0) > 0 && empty($values['proceeds_reference'])) {
            throw ValidationException::withMessages(['proceeds_reference' => 'การขายที่มีเงินรับต้องระบุเลขที่อ้างอิงใบขายหรือใบรับเงิน']);
        }
        if (! empty($values['proceeds_reference']) && AssetDisposal::query()->where('proceeds_reference', trim($values['proceeds_reference']))->whereNotIn('status', ['CANCELLED'])->exists()) {
            throw ValidationException::withMessages(['proceeds_reference' => 'เลขที่อ้างอิงเงินรับนี้ถูกใช้แล้ว ป้องกันการรับเงินซ้ำ']);
        }

        return DB::transaction(function () use ($branch, $values, $actor): AssetDisposal {
            $disposal = AssetDisposal::query()->create([
                'document_number' => $values['document_number'], 'branch_id' => $branch->id, 'disposal_type' => $values['disposal_type'],
                'disposal_date' => $values['disposal_date'], 'status' => 'DRAFT', 'proceeds' => $values['proceeds'] ?? 0,
                'proceeds_reference' => isset($values['proceeds_reference']) ? trim($values['proceeds_reference']) : null,
                'count_reference' => isset($values['count_reference']) ? trim($values['count_reference']) : null,
                'investigation_reference' => isset($values['investigation_reference']) ? trim($values['investigation_reference']) : null,
                'override_reason' => isset($values['override_reason']) ? trim($values['override_reason']) : null,
                'reason' => trim($values['reason']), 'created_by' => $actor->id,
            ]);
            $assets = Asset::query()->with('category')->where('branch_id', $branch->id)->whereIn('id', $values['asset_ids'])->lockForUpdate()->get()->keyBy('id');
            if ($assets->count() !== count($values['asset_ids'])) {
                throw ValidationException::withMessages(['asset_ids' => 'พบสินทรัพย์บางรายการไม่อยู่ในสาขาปัจจุบัน']);
            }
            foreach ($values['asset_ids'] as $assetId) {
                $asset = $assets->get($assetId);
                $this->assertDisposable($asset);
                $carrying = max(0, (float) $asset->book_value);
                $disposal->lines()->create([
                    'asset_id' => $asset->id, 'original_status' => $asset->status, 'cost' => $asset->book_cost, 'accumulated_depreciation' => $asset->book_accumulated_depreciation,
                    'accumulated_impairment' => $asset->book_accumulated_impairment, 'carrying_amount' => $carrying,
                    'proceeds' => $values['disposal_type'] === 'SALE' ? ((float) ($values['proceeds'] ?? 0) / count($values['asset_ids'])) : 0,
                    'gain_loss' => $values['disposal_type'] === 'SALE' ? ((float) ($values['proceeds'] ?? 0) / count($values['asset_ids'])) - $carrying : -$carrying,
                ]);
            }

            return $disposal->fresh('lines');
        }, 3);
    }

    public function submit(AssetDisposal $disposal, User $actor): AssetDisposal
    {
        return $this->transition($disposal, 'DRAFT', 'SUBMITTED', ['submitted_by' => $actor->id, 'submitted_at' => now()]);
    }

    public function approve(AssetDisposal $disposal, User $actor): AssetDisposal
    {
        return $this->transition($disposal, 'SUBMITTED', 'APPROVED', ['approved_by' => $actor->id, 'approved_at' => now()], true);
    }

    public function cancel(AssetDisposal $disposal, string $reason, User $actor): AssetDisposal
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['cancellation_reason' => 'เหตุผลการยกเลิกต้องมีอย่างน้อย 10 ตัวอักษร']);
        }

        return $this->transition($disposal, ['DRAFT', 'SUBMITTED', 'APPROVED'], 'CANCELLED', ['cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => trim($reason)]);
    }

    public function post(AssetDisposal $disposal, User $actor): AssetDisposal
    {
        $accounts = $this->accounts ?? app(AssetPostingAccountResolver::class);

        return DB::transaction(function () use ($disposal, $actor, $accounts): AssetDisposal {
            $disposal = AssetDisposal::query()->with('lines.asset.category')->lockForUpdate()->findOrFail($disposal->id);
            if ($disposal->status === 'POSTED') {
                return $disposal->fresh(['lines', 'journalEntry']);
            }
            $this->assertStatus($disposal, 'APPROVED');
            $this->assertFinalDepreciationReady($disposal);
            $lines = $disposal->lines()->with('asset.category')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => 'เอกสารต้องมีสินทรัพย์อย่างน้อยหนึ่งรายการ']);
            }
            $journalLines = [];
            $provenance = [];
            foreach ($lines as $line) {
                $asset = Asset::query()->with('category')->lockForUpdate()->findOrFail($line->asset_id);
                // The current document itself is not a duplicate blocker.
                $this->assertDisposable($asset, $disposal->id);
                $category = $asset->category;
                if (! $category) {
                    throw ValidationException::withMessages(['accounts' => 'ไม่พบหมวดสินทรัพย์สำหรับลงบัญชีจำหน่าย']);
                }
                [$lineAccounts, $lineProvenance] = $this->resolveLineAccounts($disposal, $line, $category, $accounts);
                $journalLines = [...$journalLines, ...$this->lineJournal($line, $asset, $lineAccounts)];
                $provenance = [...$provenance, ...$lineProvenance];
            }
            $journal = $this->journals->postForBranchWithinTransaction([
                'source_type' => 'ASSET', 'source_id' => (string) $disposal->id, 'source_reference' => $disposal->document_number,
                'event_code' => $disposal->disposal_type === 'WRITE_OFF' ? 'asset.write_off' : 'asset.disposal',
                'entry_date' => $disposal->disposal_date->toDateString(), 'document_date' => $disposal->disposal_date->toDateString(),
                'description' => 'จำหน่ายสินทรัพย์ '.$disposal->document_number,
                'posting_metadata' => ['contract_version' => 1, 'event_code' => $disposal->disposal_type === 'WRITE_OFF' ? 'asset.write_off' : 'asset.disposal', 'accounts' => $provenance],
                'lines' => $journalLines,
            ], $disposal->branch, null, $actor);
            foreach ($lines as $line) {
                $asset = Asset::query()->lockForUpdate()->findOrFail($line->asset_id);
                $asset->update(['status' => $disposal->disposal_type === 'WRITE_OFF' ? 'WRITTEN_OFF' : 'DISPOSED', 'book_cost' => 0, 'book_accumulated_depreciation' => 0, 'book_accumulated_impairment' => 0, 'book_value' => 0, 'updated_by' => $actor->id]);
                AssetValueEvent::query()->create(['asset_id' => $asset->id, 'branch_id' => $asset->branch_id, 'event_date' => $disposal->disposal_date, 'event_type' => $disposal->disposal_type === 'WRITE_OFF' ? 'WRITE_OFF' : 'DISPOSAL', 'cost_delta' => -$line->cost, 'depreciation_delta' => -$line->accumulated_depreciation, 'impairment_delta' => -$line->accumulated_impairment, 'source_type' => 'ASSET_DISPOSAL', 'source_id' => $disposal->id, 'source_line_id' => $line->id, 'journal_entry_id' => $journal->id, 'idempotency_key' => hash('sha256', 'ASSET_DISPOSAL|'.$disposal->id.'|'.$line->id), 'created_by' => $actor->id]);
            }
            $disposal->update(['status' => 'POSTED', 'journal_entry_id' => $journal->id, 'posted_by' => $actor->id, 'posted_at' => now()]);

            return $disposal->fresh(['lines', 'journalEntry']);
        }, 3);
    }

    public function postReadiness(AssetDisposal $disposal): array
    {
        try {
            $this->assertStatus($disposal, 'APPROVED');
            $this->assertFinalDepreciationReady($disposal, false);
            $lines = $disposal->lines()->with('asset.category')->get();
            $accounts = $this->accounts ?? app(AssetPostingAccountResolver::class);
            foreach ($lines as $line) {
                $asset = $line->asset;
                if (! $asset) {
                    throw ValidationException::withMessages(['lines' => 'ไม่พบสินทรัพย์สำหรับลงบัญชีจำหน่าย']);
                }
                $this->assertDisposable($asset, $disposal->id);
                if (! $asset->category) {
                    throw ValidationException::withMessages(['accounts' => 'ไม่พบหมวดสินทรัพย์สำหรับลงบัญชีจำหน่าย']);
                }
                $this->resolveLineAccounts($disposal, $line, $asset->category, $accounts, false);
            }

            return ['ready' => true, 'blockers' => []];
        } catch (ValidationException $exception) {
            return ['ready' => false, 'blockers' => collect($exception->errors())->flatten()->unique()->values()->all()];
        }
    }

    public function reverse(AssetDisposal $original, string $date, string $reason, User $actor): AssetDisposal
    {
        return DB::transaction(function () use ($original, $date, $reason, $actor): AssetDisposal {
            $original = AssetDisposal::query()->with(['lines', 'journalEntry'])->lockForUpdate()->findOrFail($original->id);
            $this->assertStatus($original, 'POSTED');
            if ($original->reversal_of_id || AssetDisposal::query()->where('reversal_of_id', $original->id)->exists()) {
                throw ValidationException::withMessages(['status' => 'เอกสารนี้ถูกกลับรายการแล้ว']);
            }
            if (mb_strlen(trim($reason)) < 10) {
                throw ValidationException::withMessages(['reason' => 'เหตุผลการกลับรายการต้องมีอย่างน้อย 10 ตัวอักษร']);
            }
            $journal = $original->journalEntry ? $this->journals->reverseWithinTransaction($original->journalEntry, ['source_type' => 'ASSET', 'source_id' => 'disposal:'.$original->id, 'reversal_date' => $date, 'reason' => trim($reason)], $actor) : null;
            foreach ($original->lines as $line) {
                Asset::query()->whereKey($line->asset_id)->lockForUpdate()->update(['status' => $line->original_status ?: 'ACTIVE', 'book_cost' => $line->cost, 'book_accumulated_depreciation' => $line->accumulated_depreciation, 'book_accumulated_impairment' => $line->accumulated_impairment, 'book_value' => $line->carrying_amount, 'updated_by' => $actor->id]);
            }
            $reversal = $original->replicate();
            $reversal->document_number = $original->document_number.'-R';
            $reversal->status = 'POSTED';
            $reversal->reversal_of_id = $original->id;
            $reversal->reversal_journal_entry_id = $journal?->id;
            $reversal->reversal_date = $date;
            $reversal->reversal_reason = trim($reason);
            $reversal->journal_entry_id = $journal?->id;
            $reversal->posted_by = $actor->id;
            $reversal->posted_at = now();
            $reversal->created_by = $actor->created_by;
            $reversal->save();
            foreach ($original->lines as $line) {
                $reversal->lines()->create($line->only(['asset_id', 'original_status', 'cost', 'accumulated_depreciation', 'accumulated_impairment', 'carrying_amount', 'proceeds', 'gain_loss']));
                AssetValueEvent::query()->create(['asset_id' => $line->asset_id, 'branch_id' => $original->branch_id, 'event_date' => $date, 'event_type' => 'REVERSAL', 'cost_delta' => $line->cost, 'depreciation_delta' => $line->accumulated_depreciation, 'impairment_delta' => $line->accumulated_impairment, 'source_type' => 'ASSET_DISPOSAL', 'source_id' => $reversal->id, 'source_line_id' => $line->id, 'journal_entry_id' => $journal?->id, 'idempotency_key' => hash('sha256', 'ASSET_DISPOSAL_REVERSAL|'.$original->id.'|'.$line->id), 'created_by' => $actor->id]);
            }

            return $reversal->fresh('lines');
        }, 3);
    }

    /** @return array{0: array<string, array{account: Account, provenance: array<string, int|string|null>}>, 1: array<int, array<string, int|string|null>>} */
    private function resolveLineAccounts(AssetDisposal $disposal, $line, $category, AssetPostingAccountResolver $resolver, bool $lockForUpdate = true): array
    {
        $event = $disposal->disposal_type === 'WRITE_OFF' ? 'asset.write_off' : 'asset.disposal';
        $fields = ['ASSET_COST' => 'asset_account_id'];
        if ((float) $line->accumulated_depreciation > 0) {
            $fields['ACCUMULATED_DEPRECIATION'] = 'accumulated_depreciation_account_id';
        }
        if ((float) $line->accumulated_impairment > 0) {
            $fields['ACCUMULATED_IMPAIRMENT'] = 'accumulated_impairment_account_id';
        }
        if ((float) $line->carrying_amount > (float) $line->proceeds) {
            $fields['DISPOSAL_LOSS'] = 'disposal_loss_account_id';
        }
        if ($disposal->disposal_type === 'SALE' && (float) $line->proceeds > 0) {
            $fields['DISPOSAL_CLEARING'] = 'disposal_clearing_account_id';
        }
        if ($disposal->disposal_type === 'SALE' && (float) $line->proceeds > (float) $line->carrying_amount) {
            $fields['DISPOSAL_GAIN'] = 'disposal_gain_account_id';
        }

        $resolved = [];
        foreach ($fields as $role => $field) {
            $resolved[$role] = $resolver->resolve($event, $role, $category->{$field}, 'ASSET_CATEGORY', (string) $category->id, 'MASTER', $lockForUpdate);
        }

        return [$resolved, array_values(array_map(fn (array $item) => $item['provenance'], $resolved))];
    }

    private function lineJournal($line, Asset $asset, array $accounts): array
    {
        $rows = [];
        foreach ([['role' => 'ACCUMULATED_DEPRECIATION', 'amount' => $line->accumulated_depreciation, 'label' => 'ตัดค่าเสื่อมสะสม'], ['role' => 'ACCUMULATED_IMPAIRMENT', 'amount' => $line->accumulated_impairment, 'label' => 'ตัดด้อยค่าสะสม']] as $item) {
            if ((float) $item['amount'] > 0) {
                $rows[] = ['account_id' => $accounts[$item['role']]['account']->id, 'debit' => $item['amount'], 'credit' => 0, 'subledger_type' => 'ASSET', 'subledger_id' => (string) $asset->id, 'description' => $item['label']];
            }
        }
        $proceeds = (float) $line->proceeds;
        if ($proceeds > 0) {
            $rows[] = ['account_id' => $accounts['DISPOSAL_CLEARING']['account']->id, 'debit' => $proceeds, 'credit' => 0, 'description' => 'เงินรับจากการจำหน่าย'];
        }
        $loss = max(0, (float) $line->carrying_amount - $proceeds);
        $gain = max(0, $proceeds - (float) $line->carrying_amount);
        if ($loss > 0) {
            $rows[] = ['account_id' => $accounts['DISPOSAL_LOSS']['account']->id, 'debit' => $loss, 'credit' => 0, 'description' => 'ขาดทุนจากการจำหน่าย'];
        }
        if ($gain > 0) {
            $rows[] = ['account_id' => $accounts['DISPOSAL_GAIN']['account']->id, 'debit' => 0, 'credit' => $gain, 'description' => 'กำไรจากการจำหน่าย'];
        }
        $rows[] = ['account_id' => $accounts['ASSET_COST']['account']->id, 'debit' => 0, 'credit' => $line->cost, 'subledger_type' => 'ASSET', 'subledger_id' => (string) $asset->id, 'description' => 'ตัดต้นทุนสินทรัพย์'];

        return $rows;
    }

    private function assertDisposable(Asset $asset, ?int $exceptDisposalId = null): void
    {
        if (! in_array($asset->status, ['ACTIVE', 'SUSPENDED', 'UNDER_REPAIR', 'HELD_FOR_DISPOSAL'], true)) {
            throw ValidationException::withMessages(['asset_ids' => "สินทรัพย์ {$asset->asset_number} ไม่อยู่ในสถานะที่จำหน่ายได้"]);
        } if (AssetDisposal::query()
            ->whereNull('reversal_of_id')
            ->whereHas('lines', fn ($q) => $q->where('asset_id', $asset->id))
            ->when($exceptDisposalId !== null, fn ($q) => $q->where('id', '<>', $exceptDisposalId))
            ->where(fn ($q) => $q->whereIn('status', ['SUBMITTED', 'APPROVED'])
                ->orWhere(fn ($q) => $q->where('status', 'POSTED')->whereDoesntHave('reversal')))
            ->exists()) {
            throw ValidationException::withMessages(['asset_ids' => "สินทรัพย์ {$asset->asset_number} มีเอกสารจำหน่ายค้างอยู่แล้ว"]);
        }
    }

    private function transition(AssetDisposal $d, string|array $from, string $to, array $values, bool $checkPrerequisites = false): AssetDisposal
    {
        return DB::transaction(function () use ($d, $from, $to, $values, $checkPrerequisites) {
            $d = AssetDisposal::query()->lockForUpdate()->findOrFail($d->id);
            $this->assertStatus($d, $from);
            if ($checkPrerequisites) {
                $this->assertFinalDepreciationReady($d);
            } $d->update(['status' => $to, ...$values]);

            return $d->fresh('lines');
        }, 3);
    }

    private function assertStatus(AssetDisposal $d, string|array $expected): void
    {
        if (! in_array($d->status, (array) $expected, true)) {
            throw ValidationException::withMessages(['status' => 'สถานะเอกสารไม่พร้อมสำหรับขั้นตอนนี้']);
        }
    }

    /**
     * Disposal is a period-end value event: the book depreciation run covering
     * the disposal date must be posted before approval/posting. This prevents
     * removing an asset using a stale carrying amount and also keeps posting in
     * an open fiscal period.
     */
    private function assertFinalDepreciationReady(AssetDisposal $disposal, bool $lockForUpdate = true): void
    {
        $date = $disposal->disposal_date?->toDateString();
        $period = FiscalPeriod::query()
            ->where('status', 'OPEN')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->when($lockForUpdate, fn ($query) => $query->lockForUpdate())
            ->first();
        if (! $period) {
            throw ValidationException::withMessages(['disposal_date' => 'วันที่จำหน่ายต้องอยู่ในงวดบัญชีที่เปิดอยู่']);
        }

        $lines = $disposal->lines()->with('asset')->when($lockForUpdate, fn ($query) => $query->lockForUpdate())->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['lines' => 'เอกสารต้องมีสินทรัพย์อย่างน้อยหนึ่งรายการ']);
        }
        foreach ($lines as $line) {
            $asset = $line->asset;
            if (! $asset || $asset->branch_id !== $disposal->branch_id) {
                throw ValidationException::withMessages(['lines' => 'สินทรัพย์ในเอกสารไม่อยู่ในสาขาของเอกสาร']);
            }
            if (! AssetDepreciationRun::query()
                ->where('branch_id', $disposal->branch_id)
                ->where('book_type', 'BOOK')
                ->where('status', 'POSTED')
                ->whereDate('run_through_date', '>=', $date)
                ->whereHas('lines', fn ($query) => $query->where('asset_id', $asset->id))
                ->exists()) {
                throw ValidationException::withMessages([
                    'depreciation' => "สินทรัพย์ {$asset->asset_number} ยังไม่มีชุดค่าเสื่อม Book ที่ลงบัญชีครอบคลุมวันที่จำหน่าย กรุณาคำนวณและลงบัญชีค่าเสื่อมงวดสุดท้ายก่อน",
                ]);
            }
        }
    }
}
