<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\CostRevaluationRun;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CostRevaluationReconciliationService
{
    public function check(CostRevaluationRun $run): array
    {
        $run->loadMissing('deltas.appliedCostAllocation');
        $checks = [];
        $blockers = [];
        $deltas = $run->deltas;
        $applied = $deltas->filter(fn ($delta) => $delta->appliedCostAllocation !== null);
        $journalable = $applied->filter(fn ($delta): bool => $delta->target_event !== null);
        $proofJournalIds = $journalable->pluck('appliedCostAllocation.journal_entry_id')->filter()->unique()->values();
        $correctionJournalIds = DB::table('journal_entries')->where('source_type', 'WMS_REVALUATION')->where('source_id', 'like', 'correction:run:'.$run->id.':delta:%')->pluck('id');
        $journalIds = $proofJournalIds->merge($correctionJournalIds)->unique()->values();
        $allocationIds = $journalable->pluck('appliedCostAllocation.id')->filter()->values();
        $projectionValue = $deltas->reduce(fn (BigDecimal $sum, $delta): BigDecimal => $sum->plus((string) $delta->stock_projection_delta_value), BigDecimal::zero());
        // GL journals are rounded per delta to 2 decimals. Sum the same
        // rounded impacts here; comparing with the raw high-precision plan
        // would incorrectly report a blocker from harmless line rounding.
        $expectedJournalInventory = $journalable->reduce(fn (BigDecimal $sum, $delta): BigDecimal => $sum->plus($this->expectedInventoryImpact($delta)->toScale(2, RoundingMode::HALF_UP)), BigDecimal::zero());
        $journalInventory = BigDecimal::zero();
        $journalBalance = BigDecimal::zero();
        $inventoryAccountIds = collect();
        if ($journalIds->isNotEmpty()) {
            $lines = DB::table('journal_entry_lines')->whereIn('journal_entry_id', $journalIds->all())->get(['journal_entry_id', 'account_id', 'debit', 'credit']);
            $inventoryAccountIds = DB::table('accounts')->whereIn('id', $lines->pluck('account_id')->unique()->all())->where('control_account_type', 'INVENTORY')->pluck('id');
            $journalInventory = $lines->filter(fn ($line): bool => $inventoryAccountIds->contains((int) $line->account_id))->reduce(fn (BigDecimal $sum, $line): BigDecimal => $sum->plus(BigDecimal::of((string) $line->debit)->minus((string) $line->credit)), BigDecimal::zero());
            $journalBalance = $lines->reduce(fn (BigDecimal $sum, $line): BigDecimal => $sum->plus(BigDecimal::of((string) $line->debit)->minus((string) $line->credit)), BigDecimal::zero());
        }
        $allocationStatusesReady = $applied->every(fn ($delta): bool => $delta->appliedCostAllocation->status === 'POSTED');
        $journalProofCount = $allocationIds->isEmpty() ? 0 : DB::table('wms_cost_allocation_journal_lines as links')
            ->join('journal_entry_lines as lines', 'lines.id', '=', 'links.journal_entry_line_id')
            ->join('wms_cost_allocations as allocations', 'allocations.id', '=', 'links.allocation_id')
            ->whereIn('links.allocation_id', $allocationIds->all())
            ->whereColumn('lines.journal_entry_id', 'allocations.journal_entry_id')
            ->whereColumn('links.revision', 'allocations.revision')
            ->whereNotNull('links.identity_key')
            ->distinct('links.allocation_id')
            ->count('links.allocation_id');
        $this->add($checks, $blockers, 'run_status', in_array($run->status, ['GL_POSTED', 'COMPLETED'], true), 'Run ต้องอยู่ในสถานะ GL_POSTED หรือ COMPLETED');
        $this->add($checks, $blockers, 'delta_count', $deltas->count() > 0 && $applied->count() === $deltas->count(), 'ทุก Planned Delta ต้องมี RECOST allocation');
        $this->add($checks, $blockers, 'allocation_status', $applied->count() > 0 && $allocationStatusesReady, 'ทุก RECOST allocation ต้องอยู่ในสถานะ POSTED');
        $this->add($checks, $blockers, 'journal_link_count', $proofJournalIds->count() === $journalable->count() && $journalProofCount === $journalable->count(), 'ทุก RECOST allocation ที่มี Accounting event ต้องมี Journal link ที่ตรง revision');
        $this->add($checks, $blockers, 'inventory_value', $this->zero($journalInventory->minus($expectedJournalInventory)), 'ยอด Inventory Journal ต้องตรงกับ signed revaluation event impact');
        $this->add($checks, $blockers, 'debit_credit', $this->zero($journalBalance), 'ยอด Debit/Credit ของ Journal ต้องสมดุล');
        $projected = $applied->filter(fn ($delta): bool => ! BigDecimal::of((string) $delta->stock_projection_delta_value)->isZero());
        $this->add($checks, $blockers, 'stock_projection', $projected->every(fn ($delta): bool => DB::table('wms_stock_balances')->where('warehouse_id', $delta->target_warehouse_id)->where('item_id', $delta->appliedCostAllocation->item_id)->where('uom_id', $delta->appliedCostAllocation->uom_id)->exists()), 'ต้องพบ Stock Projection ของทุก ending on-hand delta');

        return ['ready' => $blockers === [], 'blockers' => array_values(array_unique($blockers)), 'checks' => $checks, 'totals' => ['stock_projection_delta_value' => $this->out($projectionValue), 'expected_inventory_journal_value' => $this->out($expectedJournalInventory), 'journal_inventory_value' => $this->out($journalInventory), 'journal_balance' => $this->out($journalBalance), 'delta_count' => $deltas->count(), 'applied_count' => $applied->count(), 'journal_count' => $journalIds->count(), 'journal_proof_count' => $journalProofCount], 'read_only' => true];
    }

    public function complete(CostRevaluationRun $run): CostRevaluationRun
    {
        return DB::transaction(function () use ($run): CostRevaluationRun {
            $locked = CostRevaluationRun::query()->lockForUpdate()->findOrFail($run->id);
            $result = $this->check($locked);
            if (! $result['ready']) {
                throw ValidationException::withMessages(['reconciliation' => 'Reconciliation ไม่ผ่าน: '.implode(', ', $result['blockers'])]);
            }
            $locked->forceFill(['status' => 'COMPLETED'])->save();

            return $locked->fresh('deltas');
        }, 3);
    }

    private function zero(BigDecimal $value): bool { return $value->toScale(2, RoundingMode::HALF_UP)->isZero(); }
    private function out(BigDecimal $value): string { return $value->toScale(8, RoundingMode::HALF_UP)->__toString(); }
    private function expectedInventoryImpact($delta): BigDecimal
    {
        $value = BigDecimal::of((string) $delta->delta_value);
        $inventoryIsPrimary = in_array($delta->target_event, [
            'inventory.recost',
            'inventory.revaluation.sales_return',
            'inventory.revaluation.issue_return',
            'inventory.revaluation.rounding',
            'production.revaluation.material_return',
            'production.revaluation.finished_goods',
        ], true);

        return $inventoryIsPrimary ? $value : $value->negated();
    }
    private function add(array &$checks, array &$blockers, string $code, bool $pass, string $detail): void { $checks[] = ['code' => $code, 'status' => $pass ? 'PASS' : 'BLOCKED', 'detail' => $detail]; if (! $pass) { $blockers[] = $code; } }
}
