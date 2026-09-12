<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostRevaluationDelta;
use App\Modules\Wms\Models\CostRevaluationRun;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/** Resolves the effective cost a reversal must unwind without changing completed runs. */
final class CostRevaluationCompensationResolver
{
    /**
     * @param  Collection<int, CostAllocation>  $reversalAllocations
     * @return array{costs:array<int,string>,links:list<array<string,mixed>>,blockers:list<string>}
     */
    public function resolve(Collection $reversalAllocations, int $revision): array
    {
        if ($revision < 1) {
            return ['costs' => [], 'links' => [], 'blockers' => []];
        }

        $reversals = $reversalAllocations->filter(fn (CostAllocation $allocation): bool => (int) $allocation->parent_allocation_id > 0);
        $parentIds = $reversals->pluck('parent_allocation_id')->map(fn ($id): int => (int) $id)->unique()->values();
        if ($parentIds->isEmpty()) {
            return ['costs' => [], 'links' => [], 'blockers' => []];
        }

        $parents = CostAllocation::query()->whereIn('id', $parentIds->all())
            ->get(['id', 'quantity', 'unit_cost'])->keyBy('id');
        $deltas = CostRevaluationDelta::query()
            ->whereIn('allocation_id', $parentIds->all())
            ->whereNotNull('applied_cost_allocation_id')
            ->whereIn('status', ['APPLIED', 'GL_POSTED'])
            ->orderBy('id')
            ->get(['id', 'run_id', 'allocation_id', 'delta_value']);
        $runs = CostRevaluationRun::query()->whereIn('id', $deltas->pluck('run_id')->unique()->all())
            ->get(['id', 'shadow_snapshot'])->keyBy('id');

        $costs = [];
        $links = [];
        $blockers = [];
        foreach ($reversals as $reversal) {
            $parentId = (int) $reversal->parent_allocation_id;
            $parent = $parents->get($parentId);
            $reversalDate = $reversal->business_date?->format('Y-m-d');
            $parentDeltas = $deltas->where('allocation_id', $parentId);
            $missingDate = $parentDeltas->contains(function (CostRevaluationDelta $delta) use ($runs): bool {
                return ! data_get($runs->get((int) $delta->run_id)?->shadow_snapshot, 'summary.impact_date');
            });
            if ($missingDate || ! $reversalDate) {
                $blockers[] = "COMPENSATION_EFFECTIVE_DATE_MISSING:{$parentId}";

                continue;
            }
            $applied = $parentDeltas->filter(function (CostRevaluationDelta $delta) use ($runs, $reversalDate): bool {
                return (string) data_get($runs->get((int) $delta->run_id)?->shadow_snapshot, 'summary.impact_date') <= $reversalDate;
            });
            if (! $parent || $applied->isEmpty()) {
                continue;
            }

            $quantity = BigDecimal::of((string) $parent->quantity);
            if ($quantity->isLessThanOrEqualTo(0)) {
                $blockers[] = "COMPENSATION_SOURCE_QUANTITY_INVALID:{$parentId}";

                continue;
            }
            $delta = $applied->reduce(
                fn (BigDecimal $sum, CostRevaluationDelta $row): BigDecimal => $sum->plus((string) $row->delta_value),
                BigDecimal::zero(),
            );
            $effective = BigDecimal::of((string) $parent->unit_cost)
                ->plus($delta->dividedBy($quantity, 8, RoundingMode::HALF_UP));
            if ($effective->isNegative()) {
                $blockers[] = "COMPENSATION_EFFECTIVE_COST_NEGATIVE:{$parentId}";

                continue;
            }

            $costs[(int) $reversal->id] = $effective->toScale(8, RoundingMode::HALF_UP)->__toString();
            $links[] = [
                'reversal_allocation_id' => (int) $reversal->id,
                'source_allocation_id' => $parentId,
                'source_run_ids' => $applied->pluck('run_id')->map(fn ($id): int => (int) $id)->unique()->values()->all(),
                'source_delta_ids' => $applied->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                'applied_delta_value' => $delta->toScale(8, RoundingMode::HALF_UP)->__toString(),
                'effective_unit_cost' => $costs[(int) $reversal->id],
            ];
        }

        return ['costs' => $costs, 'links' => $links, 'blockers' => array_values(array_unique($blockers))];
    }
}
