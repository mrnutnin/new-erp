<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Pos\Models\CommissionRecord;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesCommissionPlanAssignment;
use App\Modules\Pos\Models\SalesOrder;
use App\Modules\Pos\Models\SalesReturn;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Creates idempotent commission facts; settlement/payment accounting remains owned by Finance. */
final class CommissionCalculationService
{
    public function calculatePostedSale(PhysicalSale $sale): void
    {
        if ($sale->status !== 'POSTED') {
            return;
        }

        $recipientId = $this->recipientId($sale);
        if (! $recipientId) {
            return;
        }
        $branchId = $this->branchIdForSale($sale);
        if (! $branchId) {
            return;
        }
        $plans = $this->plansFor($recipientId, $branchId, $sale->posting_date?->format('Y-m-d'));
        $salePlans = $plans->whereIn('plan.basis', ['POSTED_SALE', 'GROSS_PROFIT']);
        if ($salePlans->isNotEmpty()) {
            $lines = $sale->lines()->lockForUpdate()->get();
            $costs = $this->costsByLine($lines->pluck('id')->all());
            foreach ($lines as $line) {
                foreach ($salePlans as $assignment) {
                    $basis = $assignment->plan->basis;
                    $base = $basis === 'GROSS_PROFIT'
                        ? max(0, (float) $line->tax_base - (float) ($costs[$line->id] ?? 0))
                        : (float) $line->tax_base;
                    $this->record($assignment, $sale, $line->id, 'PHYSICAL_SALE', (string) $sale->id, $base, [
                        'basis' => $basis, 'document_number' => $sale->document_number,
                        'document_type' => $sale->document_type, 'posting_date' => $sale->posting_date?->format('Y-m-d'),
                        'line_number' => $line->line_number, 'tax_base' => JournalBalance::decimal($line->tax_base),
                        'cogs_amount' => JournalBalance::decimal($costs[$line->id] ?? 0),
                    ]);
                }
            }
        }

        if ($sale->document_type !== 'HS') {
            return;
        }

        $tenderAmount = $sale->tenders()->lockForUpdate()->get()
            ->reduce(fn (string $total, $tender): string => JournalBalance::add($total, $tender->amount), '0.00');
        $advanceAmount = JournalBalance::decimal((string) $sale->advanceDepositApplications()
            ->whereNull('reversed_at')->sum('amount'));
        if (JournalBalance::decimal($sale->total_amount) === '0.00' || ($tenderAmount === '0.00' && $advanceAmount === '0.00')) {
            return;
        }
        foreach ($plans->where('plan.basis', 'COLLECTED_RECEIPT') as $assignment) {
            $this->record($assignment, $sale, null, 'PHYSICAL_SALE_CASH', (string) $sale->id, (float) $sale->total_amount, [
                'basis' => 'COLLECTED_RECEIPT', 'document_number' => $sale->document_number,
                'document_type' => 'HS', 'posting_date' => $sale->posting_date?->format('Y-m-d'),
                'settled_amount' => JournalBalance::decimal($sale->total_amount),
                'tender_amount' => $tenderAmount,
                'advance_applied_amount' => $advanceAmount,
            ]);
        }
    }

    public function calculateCollectedReceipt(Settlement $settlement): void
    {
        if ($settlement->status !== 'POSTED' || $settlement->document_type !== 'RECEIPT') {
            return;
        }
        foreach ($settlement->allocationIntents()->with('openItem.journalEntryLine')->lockForUpdate()->get() as $intent) {
            $sale = $intent->openItem?->journalEntryLine?->journal_entry_id
                ? PhysicalSale::query()->where('journal_entry_id', $intent->openItem->journalEntryLine->journal_entry_id)->where('document_type', 'IV')->where('status', 'POSTED')->lockForUpdate()->first()
                : null;
            if (! $sale) {
                continue;
            }
            $recipientId = $this->recipientId($sale);
            if (! $recipientId) {
                continue;
            }
            $branchId = $this->branchIdForSale($sale);
            if (! $branchId) {
                continue;
            }
            foreach ($this->plansFor($recipientId, $branchId, $settlement->settlement_date?->format('Y-m-d'))->where('plan.basis', 'COLLECTED_RECEIPT') as $assignment) {
                $this->record($assignment, $sale, null, 'SETTLEMENT', "{$settlement->id}:{$intent->id}", (float) $intent->amount, [
                    'basis' => 'COLLECTED_RECEIPT', 'settlement_id' => $settlement->id,
                    'settlement_number' => $settlement->document_number, 'settlement_date' => $settlement->settlement_date?->format('Y-m-d'),
                    'allocation_intent_id' => $intent->id, 'allocation_amount' => JournalBalance::decimal($intent->amount),
                ]);
            }
        }
    }

    public function reverseForPostedReturn(SalesReturn $return, User $actor, string $reason): void
    {
        if ($return->status !== 'POSTED') {
            return;
        }
        $sale = $return->sale ?: PhysicalSale::query()->find($return->physical_sale_id);
        if (! $sale) {
            return;
        }
        $this->assertPhysicalSaleCanBeReversed($sale);
        foreach ($return->lines()->with('saleLine')->lockForUpdate()->get() as $line) {
            $saleLine = $line->saleLine;
            if (! $saleLine || (float) $saleLine->quantity <= 0) {
                continue;
            }
            $ratio = BigDecimal::of((string) $line->quantity)->dividedBy((string) $saleLine->quantity, 12, RoundingMode::HALF_UP);
            $records = CommissionRecord::query()->where('physical_sale_id', $return->physical_sale_id)
                ->where('physical_sale_line_id', $saleLine->id)->where('source_type', 'PHYSICAL_SALE')
                ->whereIn('status', ['PENDING', 'APPROVED', 'PAID'])->lockForUpdate()->get();
            foreach ($records as $record) {
                $key = "sales-commission:return:{$return->id}:line:{$line->id}:record:{$record->id}";
                if (CommissionRecord::query()->where('idempotency_key', $key)->exists()) {
                    continue;
                }
                $base = BigDecimal::of((string) $record->base_amount)->multipliedBy($ratio)->negated()->toScale(2, RoundingMode::HALF_UP)->__toString();
                $amount = BigDecimal::of((string) $record->commission_amount)->multipliedBy($ratio)->negated()->toScale(2, RoundingMode::HALF_UP)->__toString();
                CommissionRecord::query()->create([
                    'commission_plan_id' => $record->commission_plan_id, 'recipient_user_id' => $record->recipient_user_id,
                    'warehouse_id' => $record->warehouse_id, 'branch_id' => $record->branch_id ?: $this->branchIdForSale($sale), 'physical_sale_id' => $record->physical_sale_id,
                    'physical_sale_line_id' => $record->physical_sale_line_id, 'source_type' => 'SALES_RETURN',
                    'source_id' => "{$return->id}:{$line->id}", 'base_amount' => $base, 'rate_percent' => $record->rate_percent,
                    'commission_amount' => $amount, 'status' => 'PENDING', 'calculated_at' => now(),
                    'reversal_of_id' => $record->id, 'snapshot' => [...(array) $record->snapshot, 'return_id' => $return->id,
                        'return_number' => $return->document_number, 'return_line_id' => $line->id, 'reversal_reason' => $reason],
                    'idempotency_key' => $key,
                ]);
            }
        }

        if (JournalBalance::decimal($sale->total_amount) === '0.00') {
            return;
        }
        $ratio = BigDecimal::of((string) $return->total_amount)->dividedBy((string) $sale->total_amount, 12, RoundingMode::HALF_UP);
        $records = CommissionRecord::query()->where('physical_sale_id', $sale->id)->whereNull('physical_sale_line_id')
            ->whereIn('source_type', ['PHYSICAL_SALE_CASH', 'SETTLEMENT'])
            ->whereIn('status', ['PENDING', 'APPROVED', 'PAID'])->lockForUpdate()->get();
        foreach ($records as $record) {
            $key = "sales-commission:return:{$return->id}:record:{$record->id}";
            if (CommissionRecord::query()->where('idempotency_key', $key)->exists()) {
                continue;
            }
            CommissionRecord::query()->create([
                'commission_plan_id' => $record->commission_plan_id, 'recipient_user_id' => $record->recipient_user_id,
                'warehouse_id' => $record->warehouse_id, 'branch_id' => $record->branch_id ?: $this->branchIdForSale($sale),
                'physical_sale_id' => $record->physical_sale_id, 'physical_sale_line_id' => null,
                'source_type' => 'SALES_RETURN', 'source_id' => (string) $return->id,
                'base_amount' => BigDecimal::of((string) $record->base_amount)->multipliedBy($ratio)->negated()->toScale(2, RoundingMode::HALF_UP)->__toString(),
                'rate_percent' => $record->rate_percent,
                'commission_amount' => BigDecimal::of((string) $record->commission_amount)->multipliedBy($ratio)->negated()->toScale(2, RoundingMode::HALF_UP)->__toString(),
                'status' => 'PENDING', 'calculated_at' => now(), 'reversal_of_id' => $record->id,
                'snapshot' => [...(array) $record->snapshot, 'return_id' => $return->id, 'return_number' => $return->document_number, 'reversal_reason' => $reason],
                'idempotency_key' => $key,
            ]);
        }
    }

    public function reverseForSettlement(Settlement $settlement, User $actor, string $reason): void
    {
        foreach (CommissionRecord::query()->where('source_type', 'SETTLEMENT')->where('source_id', 'like', "{$settlement->id}:%")
            ->whereIn('status', ['PENDING', 'APPROVED'])->lockForUpdate()->get() as $record) {
            $record->forceFill(['status' => 'REVERSED', 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reason])->save();
        }
    }

    /**
     * A paid fact belongs to the future payout/clawback contract. Until that
     * contract exists, reversing its receipt would leave a paid commission
     * without a recoverable financial adjustment.
     */
    public function assertSettlementCanBeReversed(Settlement $settlement): void
    {
        $hasPaidFact = CommissionRecord::query()
            ->where('source_type', 'SETTLEMENT')
            ->where('source_id', 'like', "{$settlement->id}:%")
            ->where('status', 'PAID')
            ->exists();

        if ($hasPaidFact) {
            throw ValidationException::withMessages([
                'settlement' => 'ไม่สามารถยกเลิกใบรับชำระหนี้ได้ เพราะมีคอมมิชชั่นที่จ่ายแล้ว กรุณาดำเนินการกลับรายการจ่ายคอมมิชชั่นก่อน',
            ]);
        }
    }

    public function assertPhysicalSaleCanBeReversed(PhysicalSale $sale): void
    {
        if (CommissionRecord::query()->where('physical_sale_id', $sale->id)->where('status', 'PAID')->exists()) {
            throw ValidationException::withMessages([
                'commission' => 'ไม่สามารถคืนหรือยกเลิกเอกสารขายได้ เพราะมีคอมมิชชั่นที่จ่ายแล้ว กรุณากลับรายการจ่ายคอมมิชชั่นก่อน',
            ]);
        }
    }

    /** @return Collection<int, SalesCommissionPlanAssignment> */
    private function plansFor(int $userId, int $branchId, ?string $date): Collection
    {
        return SalesCommissionPlanAssignment::query()->with('plan')->where('user_id', $userId)
            ->where(fn ($query) => $query->where('branch_id', $branchId)->orWhereNull('branch_id'))->get()
            ->filter(fn (SalesCommissionPlanAssignment $row) => $row->plan && $row->plan->is_active
                && (! $row->plan->effective_from || $row->plan->effective_from->format('Y-m-d') <= $date)
                && (! $row->plan->effective_to || $row->plan->effective_to->format('Y-m-d') >= $date))
            ->sortByDesc(fn (SalesCommissionPlanAssignment $row) => $row->branch_id !== null)
            ->unique(fn (SalesCommissionPlanAssignment $row) => $row->commission_plan_id.':'.$row->user_id)
            ->values();
    }

    private function branchIdForSale(PhysicalSale $sale): ?int
    {
        $branchId = $sale->branch_id ?: Warehouse::query()->whereKey($sale->warehouse_id)->value('branch_id');

        return $branchId ? (int) $branchId : null;
    }

    private function recipientId(PhysicalSale $sale): ?int
    {
        if ($sale->source_type === 'SALES_ORDER') {
            $order = SalesOrder::query()->with('sourceIntake:id,prepared_by')->find($sale->source_id);
            if ($order?->sourceIntake?->prepared_by) {
                return (int) $order->sourceIntake->prepared_by;
            }
        }

        return $sale->created_by ? (int) $sale->created_by : null;
    }

    /** @param list<int> $lineIds @return array<int, string> */
    private function costsByLine(array $lineIds): array
    {
        if ($lineIds === []) {
            return [];
        }

        return DB::table('wms_cost_allocations as allocations')->join('wms_stock_movements as movements', 'movements.id', '=', 'allocations.stock_movement_id')
            ->where('movements.source_type', 'POS')->where('movements.direction', 'OUT')->where('movements.status', 'POSTED')
            ->where('allocations.status', 'POSTED')->where('allocations.cost_status', 'FINAL')
            ->whereIn(DB::raw("CAST(JSON_UNQUOTE(JSON_EXTRACT(movements.metadata, '$.physical_sale_line_id')) AS UNSIGNED)"), $lineIds)
            ->selectRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(movements.metadata, '$.physical_sale_line_id')) AS UNSIGNED) AS line_id, SUM(ABS(allocations.value)) AS amount")
            ->groupByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(movements.metadata, '$.physical_sale_line_id')) AS UNSIGNED)")
            ->pluck('amount', 'line_id')->map(fn ($amount) => BigDecimal::of((string) $amount)->toScale(2, RoundingMode::HALF_UP)->__toString())->all();
    }

    private function record(SalesCommissionPlanAssignment $assignment, PhysicalSale $sale, ?int $lineId, string $sourceType, string $sourceId, float $base, array $snapshot): void
    {
        $base = JournalBalance::decimal(number_format($base, 2, '.', ''));
        $rate = BigDecimal::of((string) $assignment->plan->rate)->toScale(4, RoundingMode::HALF_UP)->__toString();
        $amount = BigDecimal::of($base)->multipliedBy((string) $assignment->plan->rate)->dividedBy(100, 2, RoundingMode::HALF_UP)->__toString();
        $key = "sales-commission:{$sourceType}:{$sourceId}:line:".($lineId ?? 'document').":plan:{$assignment->commission_plan_id}:user:{$assignment->user_id}";

        CommissionRecord::query()->firstOrCreate(['idempotency_key' => $key], [
            'commission_plan_id' => $assignment->commission_plan_id, 'recipient_user_id' => $assignment->user_id,
            'warehouse_id' => $sale->warehouse_id, 'branch_id' => $this->branchIdForSale($sale), 'physical_sale_id' => $sale->id, 'physical_sale_line_id' => $lineId,
            'source_type' => $sourceType, 'source_id' => $sourceId, 'base_amount' => $base, 'rate_percent' => $rate,
            'commission_amount' => $amount, 'status' => 'PENDING', 'calculated_at' => now(),
            'snapshot' => ['plan_code' => $assignment->plan->code, 'plan_name' => $assignment->plan->name, 'rate_percent' => $rate, ...$snapshot],
        ]);
    }
}
