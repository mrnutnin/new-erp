<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Pos\Models\CommissionPaymentBatch;
use App\Modules\Pos\Models\CommissionPayoutBatch;
use App\Modules\Pos\Models\CommissionRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Owns the atomic selection and actual cash posting of approved POS commission facts. */
final class CommissionPayoutService
{
    public function __construct(private readonly JournalPostingService $journals, private readonly AccountMappingService $accounts) {}

    public function create(int $branchId, int $recipientId, BankAccount $bank, string $date, User $actor): CommissionPayoutBatch
    {
        return DB::transaction(function () use ($branchId, $recipientId, $bank, $date, $actor): CommissionPayoutBatch {
            $warehouse = $this->assertBank($branchId, $bank);
            $records = $this->eligibleRecords($branchId, $recipientId);
            if ($records->isEmpty()) {
                throw ValidationException::withMessages(['recipient_user_id' => 'ไม่พบรายการคอมมิชชั่นที่อนุมัติแล้วและพร้อมจ่าย']);
            }
            $total = $records->reduce(fn (string $sum, CommissionRecord $record): string => JournalBalance::add($sum, $record->commission_amount), '0.00');
            if ($total === '0.00' || str_starts_with($total, '-')) {
                throw ValidationException::withMessages(['commission_records' => 'ยอดคอมมิชชั่นสุทธิที่พร้อมจ่ายต้องมากกว่าศูนย์']);
            }

            $batch = CommissionPayoutBatch::query()->create([
                'document_number' => 'CP-TEMP-'.str()->upper(str()->random(12)), 'branch_id' => $branchId,
                'warehouse_id' => $warehouse->id, 'recipient_user_id' => $recipientId, 'bank_account_id' => $bank->id,
                'currency_code' => $bank->currency_code, 'document_date' => $date, 'total_amount' => $total,
                'status' => 'DRAFT', 'created_by' => $actor->id,
            ]);
            $batch->update(['document_number' => 'CP-'.str_pad((string) $batch->id, 8, '0', STR_PAD_LEFT)]);
            foreach ($records as $record) {
                $batch->lines()->create(['commission_record_id' => $record->id, 'amount' => $record->commission_amount]);
            }

            return $batch->fresh(['lines.commissionRecord']);
        }, 3);
    }

    public function createForPaymentBatch(CommissionPaymentBatch $paymentBatch, int $recipientId, BankAccount $bank, string $date, User $actor): CommissionPayoutBatch
    {
        return DB::transaction(function () use ($paymentBatch, $recipientId, $bank, $date, $actor): CommissionPayoutBatch {
            $parent = CommissionPaymentBatch::query()->with('lines')->lockForUpdate()->findOrFail($paymentBatch->id);
            if ($parent->status !== 'VERIFIED') {
                throw ValidationException::withMessages(['payment_batch' => 'สร้างเอกสารจ่ายได้เฉพาะชุดที่ฝ่ายการเงินตรวจสอบแล้ว']);
            }
            $warehouse = $this->assertBank((int) $parent->branch_id, $bank);
            $existing = CommissionPayoutBatch::query()->where(['payment_batch_id' => $parent->id, 'recipient_user_id' => $recipientId])->whereIn('status', ['DRAFT', 'POSTED'])->exists();
            if ($existing) {
                throw ValidationException::withMessages(['recipient_user_id' => 'พนักงานรายนี้มีเอกสารจ่ายที่กำลังดำเนินการอยู่แล้ว']);
            }
            $recordIds = $parent->lines->pluck('commission_record_id');
            $records = CommissionRecord::query()->whereIn('id', $recordIds)->where(['recipient_user_id' => $recipientId, 'status' => 'APPROVED'])->lockForUpdate()->get();
            if ($records->isEmpty()) {
                throw ValidationException::withMessages(['recipient_user_id' => 'ไม่พบรายการคอมมิชชั่นที่พร้อมจ่ายสำหรับพนักงานรายนี้']);
            }
            $total = $records->reduce(fn (string $sum, CommissionRecord $record): string => JournalBalance::add($sum, $record->commission_amount), '0.00');
            $batch = CommissionPayoutBatch::query()->create(['payment_batch_id' => $parent->id, 'document_number' => 'CP-TEMP-'.str()->upper(str()->random(12)), 'branch_id' => $parent->branch_id, 'warehouse_id' => $warehouse->id, 'recipient_user_id' => $recipientId, 'bank_account_id' => $bank->id, 'currency_code' => $bank->currency_code, 'document_date' => $date, 'total_amount' => $total, 'status' => 'DRAFT', 'created_by' => $actor->id]);
            $batch->update(['document_number' => 'CP-'.str_pad((string) $batch->id, 8, '0', STR_PAD_LEFT)]);
            foreach ($records as $record) {
                $batch->lines()->create(['commission_record_id' => $record->id, 'amount' => $record->commission_amount]);
            }

            return $batch->fresh(['lines.commissionRecord', 'recipient', 'bankAccount']);
        }, 3);
    }

    public function post(CommissionPayoutBatch $batch, User $actor): CommissionPayoutBatch
    {
        return DB::transaction(function () use ($batch, $actor): CommissionPayoutBatch {
            $batch = CommissionPayoutBatch::query()->with('lines.commissionRecord')->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'POSTED') {
                return $batch;
            }
            if ($batch->status !== 'DRAFT') {
                throw ValidationException::withMessages(['payout_batch' => 'Post ได้เฉพาะชุดจ่ายคอมมิชชั่นร่าง']);
            }
            $bank = BankAccount::query()->whereKey($batch->bank_account_id)->where('is_active', true)->lockForUpdate()->first();
            if (! $bank) {
                throw ValidationException::withMessages(['bank_account_id' => 'บัญชีเงินสด/ธนาคารไม่พร้อมใช้งาน']);
            }
            $warehouse = $this->assertBank((int) $batch->branch_id, $bank);
            $this->assertNoPendingNegativeAdjustment($batch);
            $records = CommissionRecord::query()->whereIn('id', $batch->lines->pluck('commission_record_id'))->lockForUpdate()->get()->keyBy('id');
            if ($records->count() !== $batch->lines->count() || $records->contains(fn (CommissionRecord $record): bool => $record->status !== 'APPROVED')) {
                throw ValidationException::withMessages(['commission_records' => 'มีรายการคอมมิชชั่นที่ไม่อยู่ในสถานะอนุมัติแล้ว']);
            }
            $total = $batch->lines->reduce(fn (string $sum, $line): string => JournalBalance::add($sum, $line->amount), '0.00');
            if ($total !== JournalBalance::decimal($batch->total_amount) || $total === '0.00' || str_starts_with($total, '-')) {
                throw ValidationException::withMessages(['commission_records' => 'ยอดชุดจ่ายคอมมิชชั่นไม่ถูกต้อง']);
            }
            $expense = $this->accounts->resolve('SALES_COMMISSION_EXPENSE');
            $journal = $this->journals->postWithinTransaction([
                'source_type' => 'POS_COMMISSION', 'source_id' => (string) $batch->id, 'source_reference' => $batch->document_number,
                'event_code' => 'sales_commission_payout', 'entry_date' => $batch->document_date->format('Y-m-d'), 'document_date' => $batch->document_date->format('Y-m-d'),
                'description' => "จ่ายคอมมิชชั่น {$batch->document_number}",
                'lines' => [
                    ['account_id' => $expense->id, 'description' => "ค่าใช้จ่ายคอมมิชชั่น {$batch->recipient?->name}", 'debit' => $total, 'credit' => '0.00'],
                    ['account_id' => $bank->account_id, 'subledger_type' => strtoupper($bank->type), 'subledger_id' => (string) $bank->id, 'description' => "จ่ายคอมมิชชั่น {$batch->document_number}", 'debit' => '0.00', 'credit' => $total],
                ],
            ], $warehouse, $actor);
            CommissionRecord::query()->whereIn('id', $records->keys())->update(['status' => 'PAID', 'paid_by' => $actor->id, 'paid_at' => now()]);
            $batch->update(['status' => 'POSTED', 'journal_entry_id' => $journal->id, 'posted_by' => $actor->id, 'posted_at' => now()]);

            return $batch->fresh(['lines.commissionRecord', 'journalEntry']);
        }, 3);
    }

    public function void(CommissionPayoutBatch $batch, User $actor, string $reason): CommissionPayoutBatch
    {
        return DB::transaction(function () use ($batch, $actor, $reason): CommissionPayoutBatch {
            $batch = CommissionPayoutBatch::query()->with('lines')->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status !== 'DRAFT') {
                throw ValidationException::withMessages(['payout_batch' => 'ยกเลิกเอกสารได้เฉพาะชุดจ่ายคอมมิชชั่นร่าง']);
            }
            $batch->update(['status' => 'VOID', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason]);

            return $batch->fresh();
        }, 3);
    }

    public function reverse(CommissionPayoutBatch $batch, User $actor, string $date, string $reason): CommissionPayoutBatch
    {
        return DB::transaction(function () use ($batch, $actor, $date, $reason): CommissionPayoutBatch {
            $batch = CommissionPayoutBatch::query()->with('lines')->lockForUpdate()->findOrFail($batch->id);
            if ($batch->status === 'REVERSED') {
                return $batch;
            }
            if ($batch->status !== 'POSTED' || ! $batch->journal_entry_id) {
                throw ValidationException::withMessages(['payout_batch' => 'กลับรายการได้เฉพาะชุดจ่ายคอมมิชชั่นที่ Post แล้ว']);
            }
            $journal = JournalEntry::query()->lockForUpdate()->findOrFail($batch->journal_entry_id);
            $this->journals->reverseWithinTransaction($journal, ['source_type' => 'POS_COMMISSION', 'source_id' => "payout-reversal:{$batch->id}", 'reversal_date' => $date, 'reason' => $reason], $actor);
            CommissionRecord::query()->whereIn('id', $batch->lines->pluck('commission_record_id'))->where('status', 'PAID')->update(['status' => 'APPROVED', 'paid_by' => null, 'paid_at' => null]);
            $batch->update(['status' => 'REVERSED', 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);

            return $batch->fresh();
        }, 3);
    }

    /** @return Collection<int, CommissionRecord> */
    private function eligibleRecords(int $branchId, int $recipientId): Collection
    {
        return CommissionRecord::query()->where(['branch_id' => $branchId, 'recipient_user_id' => $recipientId, 'status' => 'APPROVED'])
            ->whereDoesntHave('payoutLines.batch', fn ($query) => $query->whereIn('status', ['DRAFT', 'POSTED']))
            ->lockForUpdate()->get();
    }

    private function assertBank(int $branchId, BankAccount $bank): Warehouse
    {
        $warehouse = Warehouse::query()->lockForUpdate()->find($bank->warehouse_id);
        if (! $warehouse || (int) $warehouse->branch_id !== $branchId || $bank->currency_code !== 'THB') {
            throw ValidationException::withMessages(['bank_account_id' => 'บัญชีเงินสด/ธนาคารต้องอยู่ในสาขาที่เลือกและเป็นสกุล THB']);
        }

        return $warehouse;
    }

    private function assertNoPendingNegativeAdjustment(CommissionPayoutBatch $batch): void
    {
        $exists = CommissionRecord::query()->where(['branch_id' => $batch->branch_id, 'recipient_user_id' => $batch->recipient_user_id, 'status' => 'PENDING'])
            ->where('commission_amount', '<', 0)->lockForUpdate()->exists();
        if ($exists) {
            throw ValidationException::withMessages(['commission_records' => 'พบรายการปรับลดคอมมิชชั่นที่ยังรออนุมัติ กรุณาอนุมัติหรือกลับรายการให้ครบก่อนจ่าย']);
        }
    }
}
