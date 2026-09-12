<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Compares the persisted Stock Balance projection with immutable WMS ledgers.
 *
 * This service is deliberately read-only. A mismatch is evidence for a
 * bounded rebuild/revaluation job; it is never repaired in the read path.
 */
final class StockBalanceProjectionReconciliationService
{
    private const TOLERANCE = '0.00000001';

    /**
     * @return array<string, mixed>
     */
    public function check(int $warehouseId, int $itemId, int $uomId, ?string $asOf = null): array
    {
        if ($warehouseId < 1 || $itemId < 1 || $uomId < 1) {
            throw new InvalidArgumentException('Warehouse, item และ UOM ต้องเป็นรหัสที่ถูกต้อง');
        }

        $movementQuery = StockMovement::query()
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->where('uom_id', $uomId)
            ->where('status', 'POSTED')
            ->when($asOf, fn ($query) => $query->where('business_date', '<=', $asOf));

        $movement = (clone $movementQuery)
            ->selectRaw('COUNT(*) AS movement_count')
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'IN' THEN base_quantity ELSE -base_quantity END), 0) AS quantity")
            ->selectRaw('MAX(business_date) AS last_business_date')
            ->first();

        $allocation = DB::table('wms_cost_allocations')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->where('uom_id', $uomId)
            ->where('status', '!=', 'REVERSED')
            ->when($asOf, fn ($query) => $query->where('business_date', '<=', $asOf))
            ->selectRaw('COUNT(*) AS allocation_count')
            ->selectRaw('COALESCE(SUM(value), 0) AS inventory_value')
            ->selectRaw("COALESCE(SUM(CASE WHEN cost_status = 'PENDING' THEN 1 ELSE 0 END), 0) AS pending_count")
            ->selectRaw("COALESCE(SUM(CASE WHEN cost_status = 'PENDING' THEN value ELSE 0 END), 0) AS pending_value")
            ->first();

        $reserved = DB::table('wms_stock_reservations')
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->where('uom_id', $uomId)
            ->where('status', 'OPEN')
            ->when($asOf, fn ($query) => $query->where('created_at', '<=', $asOf.' 23:59:59'))
            ->sum('quantity');

        $balance = StockBalance::query()
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->where('uom_id', $uomId)
            ->select(['on_hand', 'reserved', 'available', 'inventory_value', 'average_unit_cost'])
            ->first();

        $expectedQuantity = $this->decimal((string) ($movement->quantity ?? '0'));
        $expectedValue = BigDecimal::of((string) ($allocation->inventory_value ?? '0'))->toScale(2, RoundingMode::HALF_UP);
        $expectedReserved = $this->decimal((string) $reserved);
        $expectedAvailable = $expectedQuantity->minus($expectedReserved);
        $expectedAverage = $expectedQuantity->isPositive()
            ? $expectedValue->dividedBy($expectedQuantity, 8, RoundingMode::HALF_UP)
            : BigDecimal::zero();

        $actual = [
            'on_hand' => $this->decimal((string) ($balance?->on_hand ?? '0')),
            'reserved' => $this->decimal((string) ($balance?->reserved ?? '0')),
            'available' => $this->decimal((string) ($balance?->available ?? '0')),
            'inventory_value' => BigDecimal::of((string) ($balance?->inventory_value ?? '0')),
            'average_unit_cost' => BigDecimal::of((string) ($balance?->average_unit_cost ?? '0')),
        ];
        $expected = [
            'on_hand' => $expectedQuantity,
            'reserved' => $expectedReserved,
            'available' => $expectedAvailable,
            'inventory_value' => $expectedValue,
            'average_unit_cost' => $expectedAverage,
        ];

        $differences = [];
        foreach ($expected as $field => $value) {
            $delta = $actual[$field]->minus($value);
            if ($delta->abs()->isGreaterThanOrEqualTo(BigDecimal::of(self::TOLERANCE))) {
                $differences[$field] = [
                    'actual' => $this->out($actual[$field]),
                    'expected' => $this->out($value),
                    'delta' => $this->out($delta),
                ];
            }
        }

        $movementCount = (int) ($movement->movement_count ?? 0);
        $allocationCount = (int) ($allocation->allocation_count ?? 0);
        $pendingCount = (int) ($allocation->pending_count ?? 0);
        $hasLedger = $movementCount > 0 || $allocationCount > 0;
        // Stock Balance is a current projection. A historical ledger replay
        // must not be labelled MATCHED against today's persisted row.
        $historicalProjectionUnavailable = $asOf !== null;
        $status = $historicalProjectionUnavailable || (! $balance && $hasLedger)
            ? 'REBUILD_REQUIRED'
            : ($differences === [] && $pendingCount === 0 ? 'MATCHED' : 'DIFFERENCE');

        return [
            'status' => $status,
            'read_only' => true,
            'scope' => ['warehouse_id' => $warehouseId, 'item_id' => $itemId, 'uom_id' => $uomId, 'as_of' => $asOf],
            'actual' => collect($actual)->map(fn (BigDecimal $value): string => $this->out($value))->all(),
            'expected' => collect($expected)->map(fn (BigDecimal $value): string => $this->out($value))->all(),
            'differences' => $differences,
            'evidence' => [
                'movement_count' => $movementCount,
                'allocation_count' => $allocationCount,
                'pending_allocation_count' => $pendingCount,
                'pending_allocation_value' => $this->out(BigDecimal::of((string) ($allocation->pending_value ?? '0'))),
                'last_business_date' => $movement->last_business_date,
                'balance_row_exists' => (bool) $balance,
                'historical_projection_unavailable' => $historicalProjectionUnavailable,
            ],
        ];
    }

    private function decimal(string $value): BigDecimal
    {
        return BigDecimal::of($value)->toScale(8, RoundingMode::HALF_UP);
    }

    private function out(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }
}
