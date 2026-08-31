<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Finance\Models\AdvanceDeposit;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Immutable refund of an unused posted AI through every original tender account. */
final class AdvanceDepositRefundService
{
    public function __construct(private readonly JournalPostingService $journals, private readonly AuditLogger $audit) {}

    public function refund(AdvanceDeposit $deposit, string $date, string $reason, Warehouse $warehouse, User $actor, Request $request): AdvanceDeposit
    {
        return DB::transaction(function () use ($deposit, $date, $reason, $warehouse, $actor, $request): AdvanceDeposit {
            $deposit = AdvanceDeposit::query()->whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            $reason = trim($reason);
            if (mb_strlen($reason) < 10) {
                throw ValidationException::withMessages(['reason' => 'เหตุผลคืนเงินต้องมีอย่างน้อย 10 ตัวอักษร']);
            }
            if ($deposit->warehouse_id !== $warehouse->id || $deposit->party_type !== 'CUSTOMER' || $deposit->direction !== 'RECEIPT' || $deposit->instrument_type !== 'DEPOSIT') {
                throw ValidationException::withMessages(['advance_deposit' => 'ใบรับเงินล่วงหน้าไม่อยู่ในคลังหรือประเภทที่คืนเงินได้']);
            }
            if ($deposit->status === 'VOID' && $deposit->reversal_journal_entry_id) {
                return $deposit;
            }
            if (! in_array($deposit->status, ['POSTED', 'PARTIAL'], true) || ! $deposit->journal_entry_id) {
                throw ValidationException::withMessages(['status' => 'คืนเงินได้เฉพาะใบรับเงินล่วงหน้าที่ Post แล้ว']);
            }
            $deposit->applications()->lockForUpdate()->get();
            if ((float) $deposit->applied_amount > 0 || $deposit->applications()->whereNull('reversed_at')->exists()) {
                throw ValidationException::withMessages(['advance_deposit' => 'ใบรับเงินล่วงหน้าที่ถูกตัดใช้แล้วต้องย้อน allocation ก่อนคืนเงิน']);
            }
            $tenders = $deposit->tenders()->lockForUpdate()->get();
            if ($tenders->isEmpty()) {
                throw ValidationException::withMessages(['advance_deposit' => 'ไม่พบช่องทางรับเงินเดิมสำหรับกลับรายการ']);
            }
            $journal = $deposit->journalEntry()->lockForUpdate()->firstOrFail();
            $reversal = $this->journals->reverseWithinTransaction($journal, ['source_type' => 'POS', 'source_id' => "AI:{$deposit->id}:refund", 'reversal_date' => $date, 'reason' => $reason], $actor);
            $before = $deposit->only(['status', 'reversal_journal_entry_id', 'refund_bank_account_id', 'reversal_reason', 'reversed_by', 'reversed_at']);
            $deposit->update(['status' => 'VOID', 'reversal_journal_entry_id' => $reversal->id, 'refund_bank_account_id' => $tenders->count() === 1 ? $tenders->first()->bank_account_id : null, 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reason, 'reversal_key' => hash('sha256', "pos-ai-refund|{$deposit->id}|{$date}"), 'balance_amount' => '0.00']);
            $this->audit->record('pos.advance-deposit.refunded', $deposit, $before, $deposit->fresh()->only(array_keys($before)), $actor, $request);

            return $deposit->fresh();
        }, 3);
    }
}
