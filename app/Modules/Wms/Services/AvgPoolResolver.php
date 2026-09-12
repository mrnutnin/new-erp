<?php

namespace App\Modules\Wms\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Pure AVG replay for one already ordered cost-partition page. */
final class AvgPoolResolver
{
    /**
     * @param  array<string, mixed>  $anchor
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, array{unit_cost?:string,value?:string}>  $overrides
     * @return array<string, mixed>
     */
    public function resolve(array $anchor, array $rows, array $overrides = []): array
    {
        $old = $this->state(
            $anchor['old']['quantity'] ?? $anchor['quantity'] ?? '0',
            $anchor['old']['value'] ?? $anchor['value'] ?? '0',
        );
        $new = $this->state(
            $anchor['new']['quantity'] ?? $anchor['quantity'] ?? '0',
            $anchor['new']['value'] ?? $anchor['value'] ?? '0',
        );
        $propagation = (array) ($anchor['propagation'] ?? []);
        $blockers = array_values(array_unique($anchor['blockers'] ?? []));
        if (! in_array($anchor['status'] ?? null, ['READY', 'ZERO'], true)) {
            $blockers[] = 'HISTORICAL_ANCHOR_NOT_READY';
        }
        if ($blockers !== []) {
            return $this->result($old, $new, [], $blockers, $propagation);
        }

        $results = [];
        $previous = null;
        $seen = [];
        foreach ($rows as $row) {
            $id = (int) ($row['allocation_id'] ?? 0);
            $cursor = $this->cursor($row);
            $rowBlockers = array_values(array_unique($row['blockers'] ?? []));
            if ($id < 1) {
                $rowBlockers[] = 'ALLOCATION_ID_MISSING';
            } elseif (isset($seen[$id])) {
                $rowBlockers[] = 'DUPLICATE_ALLOCATION';
            }
            if ($previous !== null && $cursor <= $previous) {
                $rowBlockers[] = 'TIMELINE_ORDER_INVALID';
            }
            $quantity = $this->number($row['quantity'] ?? null, 'quantity');
            $poolQuantity = $this->number($row['pool_quantity'] ?? $row['quantity'] ?? null, 'pool quantity');
            $oldEventValue = $this->number($row['value'] ?? null, 'value');
            $type = strtoupper((string) ($row['allocation_type'] ?? ''));
            $direction = strtoupper((string) ($row['direction'] ?? ''));
            if (! in_array($direction, ['IN', 'OUT'], true)) {
                throw new InvalidArgumentException("Allocation {$id} direction must be IN or OUT.");
            }
            if ($quantity->isNegative()) {
                $rowBlockers[] = 'QUANTITY_NEGATIVE';
            }
            if (($direction === 'IN' && $oldEventValue->isNegative()) || ($direction === 'OUT' && $oldEventValue->isPositive())) {
                $rowBlockers[] = 'VALUE_DIRECTION_MISMATCH';
            }
            if ($rowBlockers !== []) {
                $blockers = [...$blockers, ...array_map(fn (string $blocker): string => "ALLOCATION_{$id}:{$blocker}", $rowBlockers)];
                break;
            }

            $seen[$id] = true;
            $previous = $cursor;

            $beforeOld = $old;
            $beforeNew = $new;
            $override = $overrides[$id] ?? $this->parentOverride($row, $quantity, $oldEventValue, $direction, $propagation);
            $newEventValue = $this->eventValue($quantity, $oldEventValue, $direction, $type, $new, $override);
            // A movement can have multiple allocation rows (for example a
            // return/reversal split). The allocation value remains per row,
            // but the movement quantity must enter the AVG pool only once.
            $quantityDelta = $type === 'RECOST' ? BigDecimal::zero() : ($direction === 'IN' ? $poolQuantity : $poolQuantity->negated());
            $old = $this->state($old['quantity']->plus($quantityDelta), $old['value']->plus($oldEventValue));
            $new = $this->state($new['quantity']->plus($quantityDelta), $new['value']->plus($newEventValue));
            $delta = $newEventValue->minus($oldEventValue);
            if (! $quantity->isZero() && ! $delta->isZero()) {
                $propagation[$id] = $this->out($delta->multipliedBy($direction === 'OUT' ? '-1' : '1')->dividedBy($quantity, 8, RoundingMode::HALF_UP));
            }

            $results[] = [
                'allocation_id' => $id,
                'movement_id' => (int) ($row['movement_id'] ?? 0),
                'parent_allocation_id' => $row['parent_allocation_id'] ?? null,
                'business_date' => $row['effective_date'] ?? $row['business_date'] ?? null,
                'impact_bucket' => $row['impact']['impact_bucket'] ?? null,
                'impact' => $row['impact'] ?? [],
                'old_unit_cost' => $this->out($this->number($row['unit_cost'] ?? '0', 'unit cost')),
                'before' => ['old' => $this->outState($beforeOld), 'new' => $this->outState($beforeNew)],
                'event' => [
                    'direction' => $direction,
                    'allocation_type' => $type,
                    'quantity' => $this->out($quantity),
                    'old_value' => $this->out($oldEventValue),
                    'new_value' => $this->out($newEventValue),
                    'delta_value' => $this->out($delta),
                    'overridden' => $override !== null,
                ],
                'after' => ['old' => $this->outState($old), 'new' => $this->outState($new)],
                'converged' => $this->same($old, $new),
            ];
        }

        return $this->result($old, $new, $results, array_values(array_unique($blockers)), $propagation);
    }

    /** @param array<string, mixed> $row @param array<int|string, string> $propagation */
    private function parentOverride(array $row, BigDecimal $quantity, BigDecimal $oldValue, string $direction, array $propagation): ?array
    {
        $parentId = (int) ($row['parent_allocation_id'] ?? 0);
        if ($parentId < 1 || ! isset($propagation[$parentId])) {
            return null;
        }
        $delta = BigDecimal::of((string) $propagation[$parentId])->multipliedBy($quantity);
        if ($direction === 'OUT') {
            $delta = $delta->negated();
        }

        return ['value' => $oldValue->plus($delta)->__toString()];
    }

    /** @param array<string, mixed>|null $override */
    private function eventValue(BigDecimal $quantity, BigDecimal $oldValue, string $direction, string $type, array $state, ?array $override): BigDecimal
    {
        if ($override !== null) {
            if (array_key_exists('value', $override)) {
                $value = $this->number($override['value'], 'override value');
                if (($direction === 'IN' && $value->isNegative()) || ($direction === 'OUT' && $value->isPositive())) {
                    throw new InvalidArgumentException('AVG override value sign must match direction.');
                }

                return $value;
            }
            if (array_key_exists('unit_cost', $override)) {
                $value = $quantity->multipliedBy($this->unsigned($override['unit_cost'], 'override unit cost'));

                return $direction === 'OUT' ? $value->negated() : $value;
            }
            throw new InvalidArgumentException('AVG override requires value or unit_cost.');
        }
        if ($type === 'RECOST' || $direction === 'IN') {
            return $oldValue;
        }

        return $quantity->multipliedBy($state['average'])->negated();
    }

    /** @return array{quantity:BigDecimal,value:BigDecimal,average:BigDecimal} */
    private function state(string|BigDecimal $quantity, string|BigDecimal $value): array
    {
        $quantity = $quantity instanceof BigDecimal ? $quantity : $this->number($quantity, 'anchor quantity');
        $value = $value instanceof BigDecimal ? $value : $this->number($value, 'anchor value');
        if ($quantity->isZero()) {
            return ['quantity' => $quantity, 'value' => BigDecimal::zero(), 'average' => BigDecimal::zero()];
        }
        $average = $quantity->isZero()
            ? BigDecimal::zero()
            : $value->dividedBy($quantity, 8, RoundingMode::HALF_UP);

        return compact('quantity', 'value', 'average');
    }

    /** @param array<string, mixed> $old @param array<string, mixed> $new */
    private function result(array $old, array $new, array $rows, array $blockers, array $propagation): array
    {
        return [
            'read_only' => true,
            'method' => 'AVG',
            'ready' => $blockers === [],
            'rows' => $rows,
            'summary' => [
                'nodes_scanned' => count($rows),
                'nodes_affected' => count(array_filter($rows, fn (array $row): bool => $row['event']['delta_value'] !== '0.00000000')),
                'ending_old' => $this->outState($old),
                'ending_new' => $this->outState($new),
                'ending_delta_value' => $this->out($new['value']->minus($old['value'])),
                'converged' => $this->same($old, $new),
                'propagation' => $propagation,
            ],
            'blockers' => $blockers,
        ];
    }

    /** @param array<string, mixed> $state */
    private function outState(array $state): array
    {
        return [
            'quantity' => $this->out($state['quantity']),
            'value' => $this->out($state['value']),
            'average_unit_cost' => $this->out($state['average']),
        ];
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function same(array $left, array $right): bool
    {
        return $this->outState($left) === $this->outState($right);
    }

    /** @param array<string, mixed> $row */
    private function cursor(array $row): string
    {
        $cursor = $row['cursor'] ?? null;
        if (! is_array($cursor) || ! isset($cursor['business_date'], $cursor['movement_id'], $cursor['allocation_id'])) {
            throw new InvalidArgumentException('AVG timeline row requires a complete cursor.');
        }

        return sprintf('%s|%020d|%020d', $cursor['business_date'], $cursor['movement_id'], $cursor['allocation_id']);
    }

    private function unsigned(mixed $value, string $field): BigDecimal
    {
        $value = $this->number($value, $field);
        if ($value->isNegative()) {
            throw new InvalidArgumentException("AVG {$field} cannot be negative.");
        }

        return $value;
    }

    private function number(mixed $value, string $field): BigDecimal
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("AVG {$field} must be numeric.");
        }

        return BigDecimal::of((string) $value);
    }

    private function out(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }
}
