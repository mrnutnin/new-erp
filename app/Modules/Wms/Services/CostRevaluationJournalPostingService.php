<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostRevaluationDelta;
use App\Modules\Wms\Models\CostRevaluationRun;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CostRevaluationJournalPostingService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly AccountMappingService $mappings,
        private readonly InventoryCostAllocationService $allocations,
    ) {}

    public function post(CostRevaluationRun $run, ?User $actor = null): CostRevaluationRun
    {
        if (! config('erp.inventory.revaluation_gl_posting_enabled', false)) {
            throw ValidationException::withMessages(['revaluation' => 'Revaluation GL Posting feature gate ยังปิดอยู่']);
        }

        return DB::transaction(function () use ($run, $actor): CostRevaluationRun {
            $locked = CostRevaluationRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($locked->status, ['STOCK_PROJECTED', 'GL_POSTED'], true)) {
                throw ValidationException::withMessages(['status' => 'Revaluation Run ต้องอยู่ในสถานะ STOCK_PROJECTED ก่อนลงบัญชี']);
            }
            $postingDate = $locked->posting_date?->format('Y-m-d');
            if (! $postingDate || ! FiscalPeriod::query()->where('start_date', '<=', $postingDate)->where('end_date', '>=', $postingDate)->where('status', 'OPEN')->exists()) {
                throw ValidationException::withMessages(['entry_date' => 'วันที่ลงบัญชีของ Revaluation ต้องอยู่ในงวดบัญชีที่เปิดอยู่']);
            }

            $deltas = $locked->deltas()->with('appliedCostAllocation')->orderBy('id')->lockForUpdate()->get();
            if ($deltas->isEmpty()) {
                throw ValidationException::withMessages(['delta' => 'Revaluation Run ไม่มี Delta จึงไม่สามารถลงบัญชี GL ได้']);
            }

            foreach ($deltas as $delta) {
                $allocation = $delta->appliedCostAllocation;
                if (! $allocation || $delta->status === 'PLANNED') {
                    throw ValidationException::withMessages(['delta' => 'ยังไม่มี RECOST allocation ที่พร้อมลงบัญชี']);
                }
                if ($delta->target_event === null && $delta->impact_bucket === 'TRANSFER_BRIDGE') {
                    if ($allocation->status !== 'POSTED') {
                        throw ValidationException::withMessages(['delta' => 'Transfer bridge RECOST ต้องเป็น NO_GL/POSTED']);
                    }
                    $delta->forceFill(['status' => 'GL_POSTED'])->save();

                    continue;
                }
                if ($allocation->journal_entry_id !== null) {
                    continue;
                }

                $plan = $this->postingPlan($delta, $allocation);
                $primary = $this->mappings->resolveForEvent($plan['event'], $plan['primary_role']);
                $offset = $this->mappings->resolveForEvent($plan['event'], $plan['offset_role']);
                $amount = $plan['delta']->abs()->toScale(2, RoundingMode::HALF_UP)->__toString();
                $sourceId = 'run:'.$locked->id.':allocation:'.$allocation->id;
                $warehouse = Warehouse::query()->whereKey($delta->target_warehouse_id)->where('branch_id', $delta->target_branch_id)->firstOrFail();
                $journal = $this->journals->postWithinTransaction([
                    'source_type' => 'WMS_REVALUATION',
                    'source_id' => $sourceId,
                    'source_reference' => 'WMS Revaluation Run #'.$locked->id,
                    'event_code' => $plan['event'],
                    // The source business date may be in a closed period. The
                    // Run posting date is the approved accounting-policy date.
                    'entry_date' => $postingDate,
                    'document_date' => $postingDate,
                    'description' => 'ปรับปรุงต้นทุนย้อนหลังจาก Revaluation Run #'.$locked->id,
                    'posting_metadata' => [
                        'contract_version' => 1,
                        'event_code' => $plan['event'],
                        'revaluation_run_id' => $locked->id,
                        'impact_bucket' => $delta->impact_bucket,
                        'impact_date' => data_get($locked->shadow_snapshot, 'summary.impact_date'),
                        'posting_date' => $postingDate,
                        'accounts' => [$primary['provenance'], $offset['provenance']],
                    ],
                    'lines' => $plan['delta']->isPositive() ? [
                        $this->line($primary['account'], $allocation, $amount, '0.00'),
                        $this->line($offset['account'], $allocation, '0.00', $amount),
                    ] : [
                        $this->line($offset['account'], $allocation, $amount, '0.00'),
                        $this->line($primary['account'], $allocation, '0.00', $amount),
                    ],
                ], $warehouse, $actor);
                $line = $journal->lines()->where('account_id', $primary['account']->id)->lockForUpdate()->firstOrFail();
                $this->allocations->linkJournalLineWithinTransaction($allocation, $line);
                $delta->forceFill(['status' => 'GL_POSTED'])->save();
            }

            $locked->forceFill(['status' => 'GL_POSTED'])->save();

            return $locked->fresh('deltas.appliedCostAllocation');
        }, 3);
    }

    /** @return array{event:string,primary_role:string,offset_role:string,delta:BigDecimal} */
    private function postingPlan(CostRevaluationDelta $delta, CostAllocation $allocation): array
    {
        $event = (string) $delta->target_event;
        // RECOST allocation value is persisted as an absolute amount. The
        // signed accounting effect must come from the planned revaluation
        // delta, otherwise negative deltas can be posted with the wrong side.
        $value = BigDecimal::of((string) $delta->delta_value);
        [$primary, $offset] = match ($event) {
            'inventory.recost' => ['INVENTORY', $value->isPositive() ? 'RECOST_GAIN' : 'RECOST_LOSS'],
            'inventory.revaluation.cogs' => ['COGS', 'INVENTORY'],
            'inventory.revaluation.issue_expense' => ['ISSUE_EXPENSE', 'INVENTORY'],
            'inventory.revaluation.sales_return' => ['INVENTORY', 'COGS'],
            'inventory.revaluation.issue_return' => ['INVENTORY', 'ISSUE_EXPENSE'],
            'inventory.revaluation.rounding' => ['INVENTORY', $value->isPositive() ? 'ROUNDING_GAIN' : 'ROUNDING_LOSS'],
            'production.revaluation.wip' => ['WIP', 'INVENTORY'],
            'production.revaluation.finished_goods' => ['FINISHED_GOODS', 'WIP'],
            'production.revaluation.material_return' => ['INVENTORY', 'WIP'],
            'purchasing.revaluation.return_cost' => ['PURCHASE_RETURN_VARIANCE', 'INVENTORY'],
            default => throw ValidationException::withMessages(['target_event' => "ยังไม่รองรับ Classified Revaluation event {$event}"]),
        };

        return ['event' => $event, 'primary_role' => $primary, 'offset_role' => $offset, 'delta' => $value];
    }

    /** @return array<string, int|string|null> */
    private function line($account, CostAllocation $allocation, string $debit, string $credit): array
    {
        $controlled = $account->control_account_type !== null;

        return [
            'account_id' => (int) $account->id,
            'subledger_type' => $controlled ? 'ITEM' : null,
            'subledger_id' => $controlled ? (string) $allocation->item_id : null,
            'description' => 'Cost Revaluation · '.$allocation->id,
            'debit' => $debit,
            'credit' => $credit,
        ];
    }
}
