<?php

namespace App\Modules\Wms\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Pure FIFO replay for one ordered cost partition; never writes ledger data. */
final class FifoLayerResolver
{
    /**
     * @param  array<string, mixed>  $anchor
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, array{unit_cost?:string,value?:string}>  $overrides
     * @return array<string, mixed>
     */
    public function resolve(array $anchor, array $rows, array $overrides = []): array
    {
        $old = $this->layers($anchor['old']['layers'] ?? $anchor['layers'] ?? []);
        $new = $this->layers($anchor['new']['layers'] ?? $anchor['layers'] ?? []);
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

            $quantity = $this->unsigned($row['quantity'] ?? null, 'quantity');
            $oldValue = $this->number($row['value'] ?? null, 'value');
            $direction = strtoupper((string) ($row['direction'] ?? ''));
            $type = strtoupper((string) ($row['allocation_type'] ?? ''));
            $layerId = (int) ($row['stock_cost_layer_id'] ?? 0);
            if (! in_array($direction, ['IN', 'OUT'], true)) {
                throw new InvalidArgumentException("Allocation {$id} direction must be IN or OUT.");
            }
            if ($type === 'RECOST') {
                $rowBlockers[] = 'FIFO_RECOST_REQUIRES_LAYER_REBUILD';
            }
            if ($layerId < 1) {
                $rowBlockers[] = 'FIFO_LAYER_MISSING';
            }
            if (($direction === 'IN' && $oldValue->isNegative()) || ($direction === 'OUT' && $oldValue->isPositive())) {
                $rowBlockers[] = 'VALUE_DIRECTION_MISMATCH';
            }
            if ($rowBlockers !== []) {
                $blockers = [...$blockers, ...array_map(fn (string $blocker): string => "ALLOCATION_{$id}:{$blocker}", $rowBlockers)];
                break;
            }

            $seen[$id] = true;
            $previous = $cursor;
            $beforeOld = $this->totals($old);
            $beforeNew = $this->totals($new);
            $override = $overrides[$id] ?? $this->parentOverride($row, $quantity, $oldValue, $direction, $propagation);

            if ($direction === 'IN') {
                if (isset($old[$layerId]) || isset($new[$layerId])) {
                    $blockers[] = "ALLOCATION_{$id}:DUPLICATE_FIFO_LAYER";
                    break;
                }
                $oldUnit = $this->unitCost($row, $quantity, $oldValue);
                $newValue = $this->overrideValue($id, $quantity, $oldValue, $direction, $override);
                $newUnit = $quantity->isZero() ? BigDecimal::zero() : $newValue->dividedBy($quantity, 8, RoundingMode::HALF_UP);
                $old[$layerId] = $this->layer($layerId, $quantity, $oldUnit);
                $new[$layerId] = $this->layer($layerId, $quantity, $newUnit);
            } else {
                if (! isset($old[$layerId], $new[$layerId])) {
                    $blockers[] = "ALLOCATION_{$id}:FIFO_LAYER_NOT_IN_ANCHOR_OR_TIMELINE";
                    break;
                }
                if ($quantity->isGreaterThan($old[$layerId]['quantity']) || $quantity->isGreaterThan($new[$layerId]['quantity'])) {
                    $blockers[] = "ALLOCATION_{$id}:FIFO_LAYER_QUANTITY_EXCEEDED";
                    break;
                }
                $newValue = $this->overrideValue(
                    $id,
                    $quantity,
                    $quantity->multipliedBy($new[$layerId]['unit_cost'])->negated(),
                    $direction,
                    $override,
                );
                $old[$layerId]['quantity'] = $old[$layerId]['quantity']->minus($quantity);
                $new[$layerId]['quantity'] = $new[$layerId]['quantity']->minus($quantity);
            }

            $afterOld = $this->totals($old);
            $afterNew = $this->totals($new);
            $delta = $newValue->minus($oldValue);
            if (! $quantity->isZero() && ! $delta->isZero()) {
                $propagation[$id] = $this->out($delta->multipliedBy($direction === 'OUT' ? '-1' : '1')->dividedBy($quantity, 8, RoundingMode::HALF_UP));
            }
            $results[] = [
                'allocation_id' => $id,
                'movement_id' => (int) ($row['movement_id'] ?? 0),
                'stock_cost_layer_id' => $layerId,
                'parent_allocation_id' => $row['parent_allocation_id'] ?? null,
                'business_date' => $row['effective_date'] ?? $row['business_date'] ?? null,
                'impact_bucket' => $row['impact']['impact_bucket'] ?? null,
                'impact' => $row['impact'] ?? [],
                'before' => ['old' => $beforeOld, 'new' => $beforeNew],
                'event' => [
                    'direction' => $direction,
                    'allocation_type' => $type,
                    'quantity' => $this->out($quantity),
                    'old_value' => $this->out($oldValue),
                    'new_value' => $this->out($newValue),
                    'delta_value' => $this->out($delta),
                    'overridden' => $override !== null,
                ],
                'after' => ['old' => $afterOld, 'new' => $afterNew],
                'converged' => $afterOld === $afterNew,
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

    /** @param list<array<string, mixed>> $layers @return array<int, array<string, mixed>> */
    private function layers(array $layers): array
    {
        $result = [];
        foreach ($layers as $layer) {
            $id = (int) ($layer['layer_id'] ?? 0);
            if ($id < 1 || isset($result[$id])) {
                throw new InvalidArgumentException('FIFO anchor layer identity must be positive and unique.');
            }
            $result[$id] = $this->layer($id, $this->unsigned($layer['quantity'] ?? null, 'layer quantity'), $this->unsigned($layer['unit_cost'] ?? null, 'layer unit cost'));
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function layer(int $id, BigDecimal $quantity, BigDecimal $unitCost): array
    {
        return ['layer_id' => $id, 'quantity' => $quantity, 'unit_cost' => $unitCost];
    }

    /** @param array<int, array<string, mixed>> $layers */
    private function totals(array $layers): array
    {
        $quantity = BigDecimal::zero();
        $value = BigDecimal::zero();
        foreach ($layers as $layer) {
            $quantity = $quantity->plus($layer['quantity']);
            $value = $value->plus($layer['quantity']->multipliedBy($layer['unit_cost']));
        }

        return ['quantity' => $this->out($quantity), 'value' => $this->out($value)];
    }

    /** @param array<string, mixed> $row */
    private function unitCost(array $row, BigDecimal $quantity, BigDecimal $value): BigDecimal
    {
        if (isset($row['unit_cost'])) {
            return $this->unsigned($row['unit_cost'], 'unit cost');
        }

        return $quantity->isZero() ? BigDecimal::zero() : $value->abs()->dividedBy($quantity, 8, RoundingMode::HALF_UP);
    }

    /** @param array<string, mixed>|null $override */
    private function overrideValue(int $id, BigDecimal $quantity, BigDecimal $default, string $direction, ?array $override): BigDecimal
    {
        if ($override === null) {
            return $default;
        }
        if (array_key_exists('value', $override)) {
            $value = $this->number($override['value'], 'override value');
        } elseif (array_key_exists('unit_cost', $override)) {
            $value = $quantity->multipliedBy($this->unsigned($override['unit_cost'], 'override unit cost'));
            $value = $direction === 'OUT' ? $value->negated() : $value;
        } else {
            throw new InvalidArgumentException("FIFO allocation {$id} override requires value or unit_cost.");
        }
        if (($direction === 'IN' && $value->isNegative()) || ($direction === 'OUT' && $value->isPositive())) {
            throw new InvalidArgumentException('FIFO override value sign must match direction.');
        }

        return $value;
    }

    /** @param array<int, array<string, mixed>> $old @param array<int, array<string, mixed>> $new */
    private function result(array $old, array $new, array $rows, array $blockers, array $propagation): array
    {
        $oldTotals = $this->totals($old);
        $newTotals = $this->totals($new);

        return [
            'read_only' => true,
            'method' => 'FIFO',
            'ready' => $blockers === [],
            'rows' => $rows,
            'summary' => [
                'nodes_scanned' => count($rows),
                'nodes_affected' => count(array_filter($rows, fn (array $row): bool => $row['event']['delta_value'] !== '0.00000000')),
                'ending_old' => $oldTotals,
                'ending_new' => $newTotals,
                'ending_delta_value' => $this->out(BigDecimal::of($newTotals['value'])->minus($oldTotals['value'])),
                'converged' => $oldTotals === $newTotals,
                'old_layers' => $this->outLayers($old),
                'new_layers' => $this->outLayers($new),
                'propagation' => $propagation,
            ],
            'blockers' => $blockers,
        ];
    }

    /** @param array<int, array<string, mixed>> $layers */
    private function outLayers(array $layers): array
    {
        return array_values(array_map(fn (array $layer): array => [
            'layer_id' => $layer['layer_id'],
            'quantity' => $this->out($layer['quantity']),
            'unit_cost' => $this->out($layer['unit_cost']),
        ], $layers));
    }

    /** @param array<string, mixed> $row */
    private function cursor(array $row): string
    {
        $cursor = $row['cursor'] ?? null;
        if (! is_array($cursor) || ! isset($cursor['business_date'], $cursor['movement_id'], $cursor['allocation_id'])) {
            throw new InvalidArgumentException('FIFO timeline row requires a complete cursor.');
        }

        return sprintf('%s|%020d|%020d', $cursor['business_date'], $cursor['movement_id'], $cursor['allocation_id']);
    }

    private function unsigned(mixed $value, string $field): BigDecimal
    {
        $value = $this->number($value, $field);
        if ($value->isNegative()) {
            throw new InvalidArgumentException("FIFO {$field} cannot be negative.");
        }

        return $value;
    }

    private function number(mixed $value, string $field): BigDecimal
    {
        if (! is_numeric($value)) {
            throw new InvalidArgumentException("FIFO {$field} must be numeric.");
        }

        return BigDecimal::of((string) $value);
    }

    private function out(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }
}
