<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockMovement;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class StockBalanceService
{
    public function forItem(int $warehouseId, int $itemId, ?int $uomId = null, ?string $asOf = null, string $reserved = '0'): array
    {
        if ($warehouseId < 1 || $itemId < 1 || ($uomId !== null && $uomId < 1)) {
            throw new InvalidArgumentException('Warehouse, item และ UOM ต้องเป็นรหัสที่ถูกต้อง');
        }
        $date = $asOf ?: now()->toDateString();
        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $date)->format('Y-m-d');
        } catch (\Throwable) {
            throw new InvalidArgumentException('วันที่ต้องเป็นรูปแบบ Y-m-d');
        }

        $isCurrent = $asOf === null;
        if ($isCurrent) {
            $balance = StockBalance::query()
                ->where('warehouse_id', $warehouseId)
                ->where('item_id', $itemId)
                ->when($uomId, fn ($query) => $query->where('uom_id', $uomId))
                ->selectRaw('COALESCE(SUM(on_hand), 0) AS on_hand')
                ->selectRaw('COALESCE(SUM(reserved), 0) AS reserved')
                ->selectRaw('COALESCE(SUM(available), 0) AS available')
                ->first();

            return [
                'on_hand' => $balance?->on_hand ?? '0.00000000',
                'reserved' => $balance?->reserved ?? '0.00000000',
                'available' => $balance?->available ?? '0.00000000',
                'warehouse_id' => $warehouseId,
                'item_id' => $itemId,
                'uom_id' => $uomId,
                'as_of' => $date,
            ];
        }

        // Historical reads use SQL aggregation, not a PHP collection of every
        // movement. This keeps Stock Card bounded when a warehouse has years
        // of ledger history; the persisted projection remains the current view.
        $totals = StockMovement::query()
            ->where('warehouse_id', $warehouseId)
            ->where('item_id', $itemId)
            ->when($uomId, fn ($query) => $query->where('uom_id', $uomId))
            ->where('status', 'POSTED')
            ->where('business_date', '<=', $date)
            ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'IN' THEN base_quantity ELSE -base_quantity END), 0) AS on_hand")
            ->value('on_hand');

        return [
            'on_hand' => BigDecimal::of((string) $totals)->toScale(8)->__toString(),
            'reserved' => BigDecimal::of((string) $reserved)->toScale(8)->__toString(),
            'available' => BigDecimal::of((string) $totals)->minus($reserved)->toScale(8)->__toString(),
            'warehouse_id' => $warehouseId,
            'item_id' => $itemId,
            'uom_id' => $uomId,
            'as_of' => $date,
        ];
    }
}
