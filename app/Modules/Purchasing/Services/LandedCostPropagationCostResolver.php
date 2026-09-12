<?php

namespace App\Modules\Purchasing\Services;

use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Wms\Models\CostAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class LandedCostPropagationCostResolver
{
    /** @return array<int, string> */
    public function resolve(LandedCost $landedCost): array
    {
        $rootIds = $landedCost->allocations()->pluck('wms_cost_allocation_id')->filter()->values();
        $recostRows = CostAllocation::query()->whereIn('id', $rootIds->all())
            ->where('allocation_type', 'RECOST')->where('status', '!=', 'REVERSED')
            ->get(['id', 'parent_allocation_id']);
        $parents = CostAllocation::query()->whereIn('id', $recostRows->pluck('parent_allocation_id')->filter()->unique()->all())
            ->get(['id', 'quantity', 'unit_cost'])->keyBy('id');
        $cumulative = CostAllocation::query()
            ->whereIn('parent_allocation_id', $parents->keys()->all())
            ->where('allocation_type', 'RECOST')->where('status', '!=', 'REVERSED')
            ->where('idempotency_key', 'like', 'landed-cost:%')
            ->where('business_date', '<=', $landedCost->business_date->format('Y-m-d'))
            ->selectRaw('parent_allocation_id, SUM(value) AS delta_value')
            ->groupBy('parent_allocation_id')->pluck('delta_value', 'parent_allocation_id');

        return $parents->mapWithKeys(function (CostAllocation $parent) use ($cumulative): array {
            $quantity = BigDecimal::of((string) $parent->quantity);
            if (! $quantity->isPositive()) {
                throw ValidationException::withMessages(['allocations' => "Landed Cost root Allocation #{$parent->id} มีจำนวนไม่มากกว่าศูนย์"]);
            }
            $deltaPerUnit = BigDecimal::of((string) ($cumulative->get($parent->id) ?? '0'))
                ->dividedBy($quantity, 8, RoundingMode::HALF_UP);

            return [(int) $parent->id => BigDecimal::of((string) $parent->unit_cost)->plus($deltaPerUnit)->toScale(8, RoundingMode::HALF_UP)->__toString()];
        })->all();
    }
}
