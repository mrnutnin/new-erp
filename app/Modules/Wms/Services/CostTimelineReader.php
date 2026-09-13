<?php

namespace App\Modules\Wms\Services;

use App\Models\Warehouse;
use App\Modules\Wms\Models\CostAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Throwable;

/** Reads one cost partition in deterministic, bounded pages without writes. */
final class CostTimelineReader
{
    public function __construct(
        private readonly EffectiveDocumentDateResolver $dates,
        private readonly CostImpactClassifier $impacts,
        private readonly InventoryCostAllocationService $allocations,
    ) {}

    /**
     * @param  array{business_date:string,movement_id:int,allocation_id:int}|null  $cursor
     * @return array<string, mixed>
     */
    public function read(
        int $warehouseId,
        int $itemId,
        int $uomId,
        string $method,
        string $impactStartDate,
        ?array $cursor = null,
        int $limit = 250,
        int $anchorLimit = 5000,
    ): array {
        $scope = $this->scope($warehouseId, $itemId, $uomId, $method, $impactStartDate);
        $cursor = $this->cursor($cursor);
        $limit = max(1, min($limit, 1000));
        $anchorLimit = max(1, min($anchorLimit, 10000));
        $query = $this->partitionQuery($scope)
            ->with('movement:id,warehouse_id,item_id,uom_id,movement_type,direction,status,source_type,source_id,source_reference,business_date,base_quantity,metadata')
            ->where('business_date', '>=', $scope['impact_start_date']);

        if ($cursor) {
            $query->where(function (Builder $query) use ($cursor): void {
                $query->where('business_date', '>', $cursor['business_date'])
                    ->orWhere(function (Builder $query) use ($cursor): void {
                        $query->where('business_date', $cursor['business_date'])
                            ->where('stock_movement_id', '>', $cursor['movement_id']);
                    })->orWhere(function (Builder $query) use ($cursor): void {
                        $query->where('business_date', $cursor['business_date'])
                            ->where('stock_movement_id', $cursor['movement_id'])
                            ->where('id', '>', $cursor['allocation_id']);
                    });
            });
        }

        $page = $query->orderBy('business_date')->orderBy('stock_movement_id')->orderBy('id')
            ->limit($limit + 1)->get([
                'id', 'stock_movement_id', 'stock_cost_layer_id', 'parent_allocation_id',
                'warehouse_id', 'item_id', 'uom_id', 'allocation_type', 'direction',
                'cost_status', 'status', 'method', 'revision', 'journal_entry_id', 'quantity', 'unit_cost',
                'value', 'business_date',
            ]);
        $this->annotateMovementQuantityOwners($page);
        $hasMore = $page->count() > $limit;
        $this->dates->prime($page);
        $rows = $page->take($limit)->map(function (CostAllocation $allocation) use ($scope): array {
            $date = $this->dates->resolve($allocation);
            $impact = $this->impacts->classify($allocation, '0', $scope['branch_id'], 'TIMELINE');
            $blockers = array_values(array_unique([...$date['blockers'], ...$impact->blockers]));

            return [
                'allocation_id' => (int) $allocation->id,
                'movement_id' => (int) $allocation->stock_movement_id,
                'stock_cost_layer_id' => $allocation->stock_cost_layer_id ? (int) $allocation->stock_cost_layer_id : null,
                'parent_allocation_id' => $allocation->parent_allocation_id ? (int) $allocation->parent_allocation_id : null,
                'business_date' => $allocation->business_date?->format('Y-m-d'),
                'effective_date' => $date['effective_date'],
                'direction' => (string) $allocation->direction,
                'allocation_type' => (string) $allocation->allocation_type,
                'quantity' => (string) $allocation->quantity,
                'pool_quantity' => ((int) ($allocation->movement_quantity_owner ?? 0) === 1 && $allocation->allocation_type !== 'RECOST')
                    ? (string) ($allocation->movement?->base_quantity ?? $allocation->quantity)
                    : '0.00000000',
                'unit_cost' => (string) $allocation->unit_cost,
                'value' => (string) $allocation->value,
                'cost_status' => (string) $allocation->cost_status,
                'status' => (string) $allocation->status,
                'impact' => $impact->toArray(),
                'warnings' => $impact->warnings,
                'blockers' => $blockers,
                'cursor' => $this->allocationCursor($allocation),
            ];
        })->values();

        $anchor = $this->anchor($scope, $anchorLimit);
        $blockers = $rows->flatMap(fn (array $row): array => $row['blockers'])
            ->merge($anchor['blockers'] ?? [])->merge($scope['blockers'])->unique()->sort()->values()->all();

        return [
            'read_only' => true,
            'scope' => $scope,
            'anchor' => $anchor,
            'impact_window' => [
                'start_date' => $scope['impact_start_date'],
                'page_end_date' => $rows->pluck('effective_date')->filter()->max(),
            ],
            'rows' => $rows->all(),
            'page' => [
                'limit' => $limit,
                'nodes_scanned' => $rows->count(),
                'has_more' => $hasMore,
                'next_cursor' => $hasMore ? ($rows->last()['cursor'] ?? null) : null,
            ],
            'blockers' => $blockers,
        ];
    }

    /** @param array<string, mixed> $scope */
    private function anchor(array $scope, int $limit): array
    {
        $allocations = $this->partitionQuery($scope)
            ->with('movement:id,status,business_date,base_quantity')
            ->where('business_date', '<', $scope['impact_start_date'])
            ->orderByDesc('business_date')->orderByDesc('stock_movement_id')->orderByDesc('id')
            ->limit($limit + 1)->get([
                'id', 'stock_movement_id', 'stock_cost_layer_id', 'allocation_type', 'direction', 'cost_status',
                'status', 'journal_entry_id', 'quantity', 'unit_cost', 'value', 'business_date',
            ]);
        $this->annotateMovementQuantityOwners($allocations);

        if ($allocations->count() > $limit) {
            return [
                'status' => 'REQUIRES_REBUILD',
                'basis' => 'BOUNDED_ALLOCATION_LEDGER',
                'rows' => $limit,
                'limit' => $limit,
                'blockers' => ['ANCHOR_LIMIT_EXCEEDED'],
            ];
        }

        $quantity = BigDecimal::zero();
        $value = BigDecimal::zero();
        $blockers = [];
        foreach ($allocations as $allocation) {
            $allocationQuantity = ((int) ($allocation->movement_quantity_owner ?? 0) === 1 && $allocation->allocation_type !== 'RECOST')
                ? BigDecimal::of((string) ($allocation->movement?->base_quantity ?? $allocation->quantity))
                : BigDecimal::zero();
            if ($allocation->allocation_type !== 'RECOST') {
                $quantity = $quantity->plus($allocation->direction === 'IN' ? $allocationQuantity : $allocationQuantity->negated());
            }
            $value = $value->plus(BigDecimal::of((string) $allocation->value));
            if ($allocation->cost_status === 'PENDING') {
                $blockers[] = 'ANCHOR_PENDING_COST';
            }
            if ($allocation->status !== 'POSTED') {
                $blockers[] = 'ANCHOR_ALLOCATION_NOT_POSTED';
            }
            if (! $allocation->movement) {
                $blockers[] = 'ANCHOR_MOVEMENT_MISSING';
            } elseif ($allocation->movement->status !== 'POSTED') {
                $blockers[] = 'ANCHOR_MOVEMENT_NOT_POSTED';
            } elseif ($allocation->movement->business_date?->format('Y-m-d') !== $allocation->business_date?->format('Y-m-d')) {
                $blockers[] = 'ANCHOR_DATE_MISMATCH';
            }
        }
        if ($quantity->isZero() && ! $value->isZero()) {
            $blockers[] = 'ANCHOR_VALUE_WITHOUT_QUANTITY';
        }
        $blockers = array_values(array_unique($blockers));
        $latest = $allocations->first();

        if ($scope['method'] === 'FIFO') {
            return $this->fifoAnchor($allocations, $limit, $blockers, $latest);
        }

        return [
            'status' => $blockers === [] ? ($allocations->isEmpty() ? 'ZERO' : 'READY') : 'REQUIRES_REVIEW',
            'basis' => 'BOUNDED_ALLOCATION_LEDGER',
            'rows' => $allocations->count(),
            'limit' => $limit,
            'through_date' => $latest?->business_date?->format('Y-m-d'),
            'through_cursor' => $latest ? $this->allocationCursor($latest) : null,
            'quantity' => $this->decimal($quantity),
            'value' => $this->decimal($value),
            'average_unit_cost' => $quantity->isZero()
                ? '0.00000000'
                : $this->decimal($value->dividedBy($quantity, 12, RoundingMode::HALF_UP)),
            'blockers' => $blockers,
        ];
    }

    /** Rebuild active FIFO layers before the impact date from the immutable allocation ledger. */
    private function fifoAnchor(Collection $allocations, int $limit, array $blockers, ?CostAllocation $latest): array
    {
        $layers = [];
        foreach ($allocations->reverse()->values() as $allocation) {
            $id = (int) $allocation->id;
            $layerId = (int) $allocation->stock_cost_layer_id;
            $quantity = BigDecimal::of((string) $allocation->quantity);
            if ($allocation->allocation_type === 'RECOST') {
                $blockers[] = "ALLOCATION_{$id}:FIFO_RECOST_REQUIRES_LAYER_REBUILD";

                continue;
            }
            if ($layerId < 1) {
                $blockers[] = "ALLOCATION_{$id}:FIFO_LAYER_MISSING";

                continue;
            }
            if ($quantity->isNegative()) {
                $blockers[] = "ALLOCATION_{$id}:FIFO_QUANTITY_NEGATIVE";

                continue;
            }
            if (! in_array($allocation->direction, ['IN', 'OUT'], true)) {
                $blockers[] = "ALLOCATION_{$id}:FIFO_DIRECTION_INVALID";

                continue;
            }
            if ($allocation->direction === 'IN') {
                if (isset($layers[$layerId])) {
                    $blockers[] = "ALLOCATION_{$id}:FIFO_DUPLICATE_LAYER_OWNER";

                    continue;
                }
                $layers[$layerId] = [
                    'layer_id' => $layerId,
                    'quantity' => $quantity,
                    'unit_cost' => BigDecimal::of((string) $allocation->unit_cost),
                ];

                continue;
            }
            if (! isset($layers[$layerId])) {
                $blockers[] = "ALLOCATION_{$id}:FIFO_LAYER_CONSUMED_BEFORE_RECEIPT";

                continue;
            }
            if ($quantity->isGreaterThan($layers[$layerId]['quantity'])) {
                $blockers[] = "ALLOCATION_{$id}:FIFO_LAYER_QUANTITY_EXCEEDED";

                continue;
            }
            $layers[$layerId]['quantity'] = $layers[$layerId]['quantity']->minus($quantity);
        }

        $layers = array_values(array_filter($layers, fn (array $layer): bool => ! $layer['quantity']->isZero()));
        $quantity = BigDecimal::zero();
        $value = BigDecimal::zero();
        foreach ($layers as $layer) {
            $quantity = $quantity->plus($layer['quantity']);
            $value = $value->plus($layer['quantity']->multipliedBy($layer['unit_cost']));
        }
        $blockers = array_values(array_unique($blockers));

        return [
            'status' => $blockers === [] ? ($layers === [] ? 'ZERO' : 'READY') : 'REQUIRES_REVIEW',
            'basis' => 'BOUNDED_FIFO_ALLOCATION_LEDGER',
            'rows' => $allocations->count(),
            'limit' => $limit,
            'through_date' => $latest?->business_date?->format('Y-m-d'),
            'through_cursor' => $latest ? $this->allocationCursor($latest) : null,
            'quantity' => $this->decimal($quantity),
            'value' => $this->decimal($value),
            'layers' => array_map(fn (array $layer): array => [
                'layer_id' => $layer['layer_id'],
                'quantity' => $this->decimal($layer['quantity']),
                'unit_cost' => $this->decimal($layer['unit_cost']),
            ], $layers),
            'blockers' => $blockers,
        ];
    }

    /** @param array<string, mixed> $scope */
    private function partitionQuery(array $scope): Builder
    {
        $query = $this->allocations->canonicalAsOf('9999-12-31')
            ->where('warehouse_id', $scope['warehouse_id'])
            ->where('item_id', $scope['item_id'])
            ->where('uom_id', $scope['uom_id'])
            ->where('method', $scope['method'])
            ->where('status', '!=', 'REVERSED')
            // Revaluation RECOST rows are outputs of an earlier replay. They
            // must not become input events for the next replay, otherwise a
            // retry compounds the same correction and AVG never returns to
            // the movement-date average.
            ->where(function (Builder $query): void {
                $query->where('allocation_type', '!=', 'RECOST')
                    ->orWhereRaw("JSON_EXTRACT(metadata, '$.revaluation_run_id') IS NULL");
            });

        return $query;
    }

    private function annotateMovementQuantityOwners(Collection $allocations): void
    {
        $owners = $allocations->filter(fn (CostAllocation $allocation): bool => $allocation->allocation_type !== 'RECOST')
            ->groupBy('stock_movement_id')->map(fn (Collection $rows): int => (int) $rows->min('id'));
        $allocations->each(fn (CostAllocation $allocation): mixed => $allocation->setAttribute(
            'movement_quantity_owner',
            $allocation->allocation_type !== 'RECOST' && (int) $owners->get($allocation->stock_movement_id) === (int) $allocation->id ? 1 : 0,
        ));
    }

    /** @return array<string, mixed> */
    private function scope(int $warehouseId, int $itemId, int $uomId, string $method, string $date): array
    {
        if ($warehouseId < 1 || $itemId < 1 || $uomId < 1) {
            throw new InvalidArgumentException('Warehouse, item and UOM ids must be positive.');
        }
        $method = strtoupper(trim($method));
        if (! in_array($method, ['AVG', 'FIFO'], true)) {
            throw new InvalidArgumentException('Cost method must be AVG or FIFO.');
        }
        $date = $this->date($date, 'Impact start date');
        $branchId = Warehouse::query()->whereKey($warehouseId)->value('branch_id');

        return [
            'warehouse_id' => $warehouseId,
            'branch_id' => $branchId ? (int) $branchId : null,
            'item_id' => $itemId,
            'uom_id' => $uomId,
            'method' => $method,
            'impact_start_date' => $date,
            'blockers' => $branchId ? [] : ['SCOPE_BRANCH_MISSING'],
        ];
    }

    /** @param array<string, mixed>|null $cursor */
    private function cursor(?array $cursor): ?array
    {
        if ($cursor === null) {
            return null;
        }
        if (! isset($cursor['business_date'], $cursor['movement_id'], $cursor['allocation_id'])
            || (int) $cursor['movement_id'] < 1 || (int) $cursor['allocation_id'] < 1) {
            throw new InvalidArgumentException('Timeline cursor is incomplete.');
        }
        $date = $this->date((string) $cursor['business_date'], 'Timeline cursor date');

        return ['business_date' => $date, 'movement_id' => (int) $cursor['movement_id'], 'allocation_id' => (int) $cursor['allocation_id']];
    }

    /** @return array{business_date:string,movement_id:int,allocation_id:int} */
    private function allocationCursor(CostAllocation $allocation): array
    {
        return [
            'business_date' => $allocation->business_date->format('Y-m-d'),
            'movement_id' => (int) $allocation->stock_movement_id,
            'allocation_id' => (int) $allocation->id,
        ];
    }

    private function decimal(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }

    private function date(string $value, string $label): string
    {
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (Throwable) {
            throw new InvalidArgumentException("{$label} must use Y-m-d.");
        }
        if (! $date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("{$label} must use Y-m-d.");
        }

        return $value;
    }
}
