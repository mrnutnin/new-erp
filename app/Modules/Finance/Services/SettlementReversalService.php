<?php

namespace App\Modules\Finance\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Finance\Models\AdvanceDeposit;
use App\Modules\Finance\Models\Allocation;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Support\SettlementState;
use App\Modules\Pos\Services\CommissionCalculationService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SettlementReversalService
{
    public function __construct(private readonly JournalPostingService $journals, private readonly CommissionCalculationService $commissions) {}

    public function reverse(Settlement $settlement, Warehouse $warehouse, string $date, string $reason, User $actor, Request $request): Settlement
    {
        return DB::transaction(function () use ($settlement, $warehouse, $date, $reason, $actor): Settlement {
            $settlement = Settlement::query()->whereKey($settlement->id)
                ->whereHas('bankAccount', fn ($q) => $q->where('warehouse_id', $warehouse->id))
                ->lockForUpdate()->firstOrFail();
            if ($settlement->status === 'VOID' && $settlement->reversal_journal_entry_id) {
                return $settlement;
            }
            try {
                SettlementState::reverse($settlement->status);
            } catch (DomainException $exception) {
                throw ValidationException::withMessages(['status' => $exception->getMessage()]);
            }
            if (! $settlement->journal_entry_id) {
                throw ValidationException::withMessages(['status' => 'Settlement ไม่มี Journal ที่ลงบัญชีแล้ว']);
            }
            if ($settlement->document_type === 'RECEIPT') {
                $this->commissions->assertSettlementCanBeReversed($settlement);
            }
            $advance = AdvanceDeposit::query()->where('source_settlement_id', $settlement->id)->lockForUpdate()->first();
            if ($advance && $advance->applications()->whereNull('reversed_at')->exists()) {
                throw ValidationException::withMessages(['status' => 'ไม่สามารถกลับรายการได้ เพราะเงินรับล่วงหน้าถูกนำไปตัดเอกสารแล้ว']);
            }
            $journal = $settlement->journalEntry()->lockForUpdate()->firstOrFail();
            $reversal = $this->journals->reverseWithinTransaction($journal, [
                'source_type' => 'FINANCE', 'source_id' => "settlement:{$settlement->id}",
                'reversal_date' => $date, 'reason' => $reason,
            ], $actor);
            Allocation::query()->where('source_type', 'FINANCE')->where('source_id', 'like', "settlement:{$settlement->id}:intent:%")
                ->whereNull('reversal_date')->lockForUpdate()->get()->each(fn (Allocation $allocation) => $allocation->update([
                    'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_date' => $date, 'reversal_reason' => $reason,
                ]));
            DB::table('finance_tax_realizations')->where('settlement_id', $settlement->id)->whereNull('reversal_date')->update(['reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_date' => $date, 'reversal_reason' => $reason]);
            DB::table('finance_withholding_realizations')->where('settlement_id', $settlement->id)->whereNull('reversal_date')->update(['reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_date' => $date, 'reversal_reason' => $reason]);
            if ($advance) {
                $advance->update([
                    'status' => 'REVERSED',
                    'reversal_journal_entry_id' => $reversal->id,
                    'reversed_by' => $actor->id,
                    'reversed_at' => now(),
                    'reversal_reason' => $reason,
                    'reversal_key' => hash('sha256', "finance-settlement-advance-reversal|{$advance->id}|{$date}"),
                ]);
            }
            $settlement->update(['status' => 'VOID', 'reversal_journal_entry_id' => $reversal->id, 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_date' => $date, 'reversal_reason' => $reason]);
            if ($settlement->document_type === 'RECEIPT') {
                $this->commissions->reverseForSettlement($settlement, $actor, $reason);
            }

            return $settlement->fresh();
        }, 3);
    }
}
