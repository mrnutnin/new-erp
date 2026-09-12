<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\CostAllocation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Resolves explicit parent-allocation edges without writing or dispatching. */
final class CostDirectBridgeResolver
{
    public function __construct(
        private readonly EffectiveDocumentDateResolver $dates,
        private readonly CostImpactClassifier $impacts,
    ) {}

    /** @param list<array<string, mixed>> $parents @return array<string, mixed> */
    public function resolve(array $parents, int $limit = 1000): array
    {
        $limit = max(1, min($limit, 1000));
        $parents = collect($parents)->filter(fn (array $row): bool => ($row['estimated_delta_value'] ?? '0.00000000') !== '0.00000000')->keyBy('allocation_id');
        $children = collect();
        $limited = false;
        foreach ($parents->keys()->chunk(250) as $parentIds) {
            $remaining = $limit - $children->count();
            if ($remaining < 1) {
                $limited = true;
                break;
            }
            $page = CostAllocation::query()
                ->with('movement.warehouse:id,branch_id')
                ->whereIn('parent_allocation_id', $parentIds->all())
                ->where('status', '!=', 'REVERSED')
                ->where('allocation_type', '!=', 'RECOST')
                ->orderBy('parent_allocation_id')->orderBy('id')
                ->limit($remaining + 1)
                ->get([
                    'id', 'stock_movement_id', 'stock_cost_layer_id', 'parent_allocation_id',
                    'warehouse_id', 'item_id', 'uom_id', 'allocation_type', 'direction',
                    'cost_status', 'status', 'method', 'journal_entry_id', 'quantity', 'unit_cost', 'value',
                    'business_date', 'metadata',
                ]);
            if ($page->count() > $remaining) {
                $limited = true;
            }
            $children->push(...$page->take($remaining));
            if ($limited) {
                break;
            }
        }
        $this->dates->prime($children);

        $rows = $children->map(function (CostAllocation $child): array {
            $date = $this->dates->resolve($child);
            $impact = $this->impacts->classify($child, '0', relation: 'DIRECT_PARENT');
            $metadata = [...(is_array($child->movement?->metadata) ? $child->movement->metadata : []), ...(is_array($child->metadata) ? $child->metadata : [])];

            return [
                'allocation_id' => (int) $child->id,
                'parent_allocation_id' => (int) $child->parent_allocation_id,
                'warehouse_id' => (int) $child->warehouse_id,
                'branch_id' => $impact->branchId,
                'item_id' => (int) $child->item_id,
                'uom_id' => (int) $child->uom_id,
                'method' => strtoupper((string) $child->method),
                'direction' => strtoupper((string) $child->direction),
                'allocation_type' => strtoupper((string) $child->allocation_type),
                'quantity' => (string) $child->quantity,
                'business_date' => $date['effective_date'],
                'source_type' => strtoupper((string) $child->movement?->source_type),
                'source_reference' => $child->movement?->source_reference,
                'metadata' => $metadata,
                'impact' => $impact->toArray(),
                'blockers' => array_values(array_unique([...$date['blockers'], ...$impact->blockers])),
                'warnings' => $impact->warnings,
            ];
        })->all();

        return $this->compile($parents->values()->all(), $rows, $limited);
    }

    /**
     * @param  list<array<string, mixed>>  $parents
     * @param  list<array<string, mixed>>  $children
     * @return array<string, mixed>
     */
    public function compile(array $parents, array $children, bool $limited = false): array
    {
        $parents = collect($parents)->keyBy('allocation_id');
        $edges = [];
        $blockers = $limited ? ['DIRECT_BRIDGE_FAN_OUT_LIMIT_EXCEEDED'] : [];
        $quantityByParent = [];

        foreach ($children as $child) {
            $childId = (int) ($child['allocation_id'] ?? 0);
            $parentId = (int) ($child['parent_allocation_id'] ?? 0);
            $parent = $parents->get($parentId);
            $issues = array_values(array_unique([...(array) ($child['blockers'] ?? []), ...(array) ($child['warnings'] ?? [])]));
            $sourcePartition = $parent ? $this->partition($parent) : null;
            $targetPartition = $this->partition($child);
            $crossPartition = $sourcePartition !== null && $sourcePartition !== $targetPartition;
            $relation = $this->relation($child);
            if (! $parent) {
                $issues[] = 'DIRECT_BRIDGE_PARENT_MISSING';
            }
            if ($relation === null && $crossPartition) {
                $issues[] = 'DIRECT_BRIDGE_EVENT_UNSUPPORTED';
            }
            $relation ??= $crossPartition ? 'UNSUPPORTED' : 'SAME_PARTITION_TIMELINE';
            if ($childId < 1 || $childId === $parentId) {
                $issues[] = 'CYCLE_DETECTED';
            }

            $parentQuantity = BigDecimal::of((string) ($parent['quantity'] ?? '0'));
            $childQuantity = BigDecimal::of((string) ($child['quantity'] ?? '0'));
            if ($parentQuantity->isLessThanOrEqualTo(0) || $childQuantity->isLessThanOrEqualTo(0)) {
                $issues[] = 'DIRECT_BRIDGE_QUANTITY_INVALID';
            }
            $quantityByParent[$parentId] = ($quantityByParent[$parentId] ?? BigDecimal::zero())->plus($childQuantity);
            if ($parent && $quantityByParent[$parentId]->isGreaterThan($parentQuantity)) {
                $issues[] = 'DIRECT_BRIDGE_QUANTITY_EXCEEDED';
            }
            if ($parent && ($child['business_date'] ?? null) < ($parent['business_date'] ?? null)) {
                $issues[] = 'DIRECT_BRIDGE_BEFORE_PARENT_DATE';
            }

            $delta = BigDecimal::zero();
            if ($parent && $parentQuantity->isPositive() && in_array($child['direction'] ?? null, ['IN', 'OUT'], true)) {
                $parentSign = ($parent['direction'] ?? null) === 'OUT' ? '-1' : '1';
                $childSign = $child['direction'] === 'OUT' ? '-1' : '1';
                $delta = BigDecimal::of((string) $parent['estimated_delta_value'])
                    ->multipliedBy($parentSign)->multipliedBy($childSign)
                    ->multipliedBy($childQuantity)->dividedBy($parentQuantity, 8, RoundingMode::HALF_UP);
            }

            $resolvedChild = $parents->get($childId);
            if ($resolvedChild && $this->decimal($delta) !== $this->decimal(BigDecimal::of((string) $resolvedChild['estimated_delta_value']))) {
                $issues[] = 'DIRECT_BRIDGE_DELTA_MISMATCH';
            }

            $issues = array_values(array_unique($issues));
            foreach ($issues as $issue) {
                $blockers[] = "ALLOCATION_{$childId}:{$issue}";
            }
            $edges[] = [
                'parent_allocation_id' => $parentId,
                'child_allocation_id' => $childId,
                'relation' => $relation,
                'business_date' => $child['business_date'] ?? null,
                'source_reference' => $child['source_reference'] ?? null,
                'quantity' => $this->decimal($childQuantity),
                'estimated_delta_value' => $this->decimal($delta),
                'source_partition_key' => $sourcePartition,
                'target_partition_key' => $targetPartition,
                'cross_partition' => $crossPartition,
                'issues' => $issues,
            ];
        }

        $partitions = collect($edges)->where('cross_partition', true)->groupBy('target_partition_key')->map(fn ($rows, string $key): array => [
            'partition_key' => $key,
            'root_allocation_ids' => $rows->pluck('child_allocation_id')->unique()->sort()->values()->all(),
            'estimated_delta_value' => $this->decimal($rows->reduce(fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['estimated_delta_value']), BigDecimal::zero())),
            'blockers' => $rows->flatMap(fn (array $row): array => $row['issues'])->unique()->values()->all(),
        ])->sortKeys()->values()->all();

        return $this->result($edges, $partitions, array_values(array_unique($blockers)), $limited);
    }

    /** @param array<string, mixed> $row */
    private function relation(array $row): ?string
    {
        $metadata = is_array($row['metadata'] ?? null) ? $row['metadata'] : [];
        if (($row['source_type'] ?? null) === 'WMS_TRANSFER' && ($row['allocation_type'] ?? null) === 'TRANSFER' && ($row['direction'] ?? null) === 'IN') {
            return match (strtoupper((string) ($metadata['transfer_event'] ?? ''))) {
                'ACCEPT' => 'TRANSFER_ACCEPT',
                'REJECT' => 'TRANSFER_REJECT',
                default => null,
            };
        }
        if (($row['allocation_type'] ?? null) === 'RECOST') {
            return 'RECOST';
        }
        if (($row['source_type'] ?? null) === 'PURCHASING'
            && strtoupper((string) ($metadata['purchase_return_mode'] ?? '')) === 'FULL'
            && isset($metadata['reversal_of_movement_id'])) {
            return 'PURCHASE_RETURN_FULL';
        }
        if (isset($metadata['reversal_of_movement_id']) || isset($metadata['reversal_of_allocation_id'])) {
            return 'REVERSAL';
        }
        if (($row['source_type'] ?? null) === 'ISSUE_RETURN' && ($row['direction'] ?? null) === 'IN') {
            return 'ISSUE_RETURN';
        }
        if (($row['source_type'] ?? null) === 'POS' && isset($metadata['sales_return_id']) && ($row['direction'] ?? null) === 'IN') {
            return 'SALES_RETURN';
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function partition(array $row): string
    {
        return implode(':', [(int) ($row['warehouse_id'] ?? 0), (int) ($row['item_id'] ?? 0), (int) ($row['uom_id'] ?? 0), strtoupper((string) ($row['method'] ?? ''))]);
    }

    private function decimal(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }

    private function result(array $edges, array $partitions, array $blockers, bool $limited): array
    {
        return [
            'read_only' => true,
            'ready' => $blockers === [],
            'limited' => $limited,
            'edges' => $edges,
            'partitions' => $partitions,
            'blockers' => $blockers,
        ];
    }
}
