<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\CostAllocation;
use Illuminate\Support\Collection;

/**
 * Read-only Phase 1 explorer. It deliberately does not create runs, deltas,
 * journals, or projections; it only inspects immutable allocation lineage.
 */
final class CostLineageExplorer
{
    public function snapshot(int $warehouseId, ?int $itemId = null, int $limit = 1000): array
    {
        $allocations = CostAllocation::query()
            ->with(['movement:id,warehouse_id,item_id,uom_id,movement_type,direction,status,source_type,source_id,source_reference,business_date'])
            ->where('warehouse_id', $warehouseId)
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->orderBy('id')
            ->limit(max(1, min($limit, 5000)))
            ->get($this->allocationColumns());

        $nodes = $this->withParentClosure($allocations);
        $byId = $nodes->keyBy('id');
        $edges = [];
        $missingParents = [];
        $missingMovements = [];

        foreach ($nodes as $allocation) {
            if ($allocation->parent_allocation_id !== null) {
                if (! $byId->has((int) $allocation->parent_allocation_id)) {
                    $missingParents[] = (int) $allocation->id;
                } else {
                    $edges[] = [
                        'parent_id' => (int) $allocation->parent_allocation_id,
                        'child_id' => (int) $allocation->id,
                        'relation' => $allocation->allocation_type,
                        'quantity' => (string) $allocation->quantity,
                        'value' => (string) $allocation->value,
                    ];
                }
            }

            if (! $allocation->movement) {
                $missingMovements[] = (int) $allocation->id;
            }
        }

        $cycles = $this->cycles($byId);
        $rows = $nodes->map(function (CostAllocation $allocation) use ($byId, $cycles): array {
            $movement = $allocation->movement;
            $issues = [];
            if ($allocation->parent_allocation_id !== null && ! $byId->has((int) $allocation->parent_allocation_id)) {
                $issues[] = 'missing_parent';
            }
            if (! $movement) {
                $issues[] = 'missing_movement';
            }
            if (in_array((int) $allocation->id, $cycles, true)) {
                $issues[] = 'cycle';
            }

            return [
                'allocation_id' => (int) $allocation->id,
                'parent_allocation_id' => $allocation->parent_allocation_id ? (int) $allocation->parent_allocation_id : null,
                'movement_id' => $allocation->stock_movement_id ? (int) $allocation->stock_movement_id : null,
                'warehouse_id' => (int) $allocation->warehouse_id,
                'item_id' => (int) $allocation->item_id,
                'allocation_type' => (string) $allocation->allocation_type,
                'direction' => (string) $allocation->direction,
                'status' => (string) $allocation->status,
                'cost_status' => (string) $allocation->cost_status,
                'quantity' => (string) $allocation->quantity,
                'unit_cost' => (string) $allocation->unit_cost,
                'value' => (string) $allocation->value,
                'business_date' => $allocation->business_date?->format('Y-m-d'),
                'source_type' => $movement?->source_type,
                'source_id' => $movement?->source_id,
                'source_reference' => $movement?->source_reference,
                'movement_type' => $movement?->movement_type,
                'issues' => $issues,
            ];
        })->values()->all();

        return [
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'limited' => $allocations->count() >= $limit,
            'summary' => [
                'nodes' => count($rows),
                'edges' => count($edges),
                'roots' => count(array_filter($rows, fn (array $row): bool => $row['parent_allocation_id'] === null)),
                'missing_parents' => count($missingParents),
                'missing_movements' => count($missingMovements),
                'cycles' => count($cycles),
            ],
            'edges' => $edges,
            'rows' => $rows,
        ];
    }

    private function withParentClosure(Collection $allocations): Collection
    {
        $nodes = $allocations->keyBy('id');
        $pending = $allocations->pluck('parent_allocation_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values();

        for ($depth = 0; $depth < 20 && $pending->isNotEmpty(); $depth++) {
            $parents = CostAllocation::query()
                ->with(['movement:id,warehouse_id,item_id,uom_id,movement_type,direction,status,source_type,source_id,source_reference,business_date'])
                ->whereIn('id', $pending->all())
                ->get($this->allocationColumns());
            $next = collect();
            foreach ($parents as $parent) {
                if ($nodes->has($parent->id)) {
                    continue;
                }
                $nodes->put($parent->id, $parent);
                if ($parent->parent_allocation_id !== null) {
                    $next->push((int) $parent->parent_allocation_id);
                }
            }
            $pending = $next->unique()->values();
        }

        return $nodes->values();
    }

    private function cycles(Collection $nodes): array
    {
        $parents = $nodes->mapWithKeys(fn (CostAllocation $allocation): array => [(int) $allocation->id => $allocation->parent_allocation_id ? (int) $allocation->parent_allocation_id : null])->all();
        $cycles = [];

        foreach (array_keys($parents) as $id) {
            $seen = [];
            $cursor = $id;
            while ($cursor !== null && isset($parents[$cursor])) {
                if (isset($seen[$cursor])) {
                    $cycles = [...$cycles, ...array_keys($seen)];
                    break;
                }
                $seen[$cursor] = true;
                $cursor = $parents[$cursor];
            }
        }

        return array_values(array_unique($cycles));
    }

    /** @return list<string> */
    private function allocationColumns(): array
    {
        return [
            'id', 'stock_movement_id', 'parent_allocation_id', 'warehouse_id', 'item_id',
            'allocation_type', 'direction', 'status', 'cost_status', 'quantity',
            'unit_cost', 'value', 'business_date',
        ];
    }
}
