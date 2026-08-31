<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockBalanceProjectionService
{
    /**
     * Rebuild one bounded projection scope from immutable ledgers.
     *
     * This is intentionally scoped to one warehouse/item/UOM. A whole
     * warehouse rebuild should dispatch these scopes in chunks instead of
     * loading all movements into memory or replacing the table wholesale.
     */
    public function rebuild(int $warehouseId, int $itemId, int $uomId): StockBalance
    {
        return DB::transaction(function () use ($warehouseId, $itemId, $uomId): StockBalance {
            $key = ['warehouse_id' => $warehouseId, 'item_id' => $itemId, 'uom_id' => $uomId];
            StockBalance::query()->insertOrIgnore([
                ...$key,
                'on_hand' => '0', 'reserved' => '0', 'available' => '0',
                'inventory_value' => '0', 'average_unit_cost' => '0',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $balance = StockBalance::query()->where($key)->lockForUpdate()->firstOrFail();
            $onHand = StockMovement::query()
                ->where($key)->where('status', 'POSTED')
                ->selectRaw("COALESCE(SUM(CASE WHEN direction = 'IN' THEN base_quantity ELSE -base_quantity END), 0) AS quantity")
                ->value('quantity');
            $reserved = DB::table('wms_stock_reservations')
                ->where($key)->where('status', 'OPEN')->sum('quantity');
            $inventoryValue = DB::table('wms_cost_allocations')
                ->where($key)->where('status', '!=', 'REVERSED')
                ->sum('value');
            $onHand = BigDecimal::of((string) $onHand)->toScale(8, RoundingMode::UNNECESSARY);
            $reserved = BigDecimal::of((string) $reserved)->toScale(8, RoundingMode::UNNECESSARY);
            $inventoryValue = BigDecimal::of((string) $inventoryValue)->toScale(2, RoundingMode::HALF_UP);
            $averageUnitCost = $onHand->isPositive()
                ? $inventoryValue->dividedBy($onHand, 8, RoundingMode::HALF_UP)
                : BigDecimal::zero();
            $balance->update([
                'on_hand' => $onHand->__toString(),
                'reserved' => $reserved->__toString(),
                'available' => $onHand->minus($reserved)->toScale(8, RoundingMode::UNNECESSARY)->__toString(),
                'inventory_value' => $inventoryValue->__toString(),
                'average_unit_cost' => $averageUnitCost->toScale(8, RoundingMode::HALF_UP)->__toString(),
            ]);

            return $balance->fresh();
        }, 3);
    }

    public function apply(StockMovement $movement): StockBalance
    {
        if ($movement->status !== 'POSTED') {
            throw ValidationException::withMessages(['status' => 'จะสร้าง Stock Balance ได้เฉพาะ Posted Movement']);
        }

        return DB::transaction(function () use ($movement): StockBalance {
            $key = [
                'warehouse_id' => $movement->warehouse_id,
                'item_id' => $movement->item_id,
                'uom_id' => $movement->uom_id,
            ];
            // Insert first, then lock the canonical row. The unique scope on
            // the projection table makes concurrent first movements converge
            // on one balance row instead of creating duplicate projections.
            StockBalance::query()->insertOrIgnore([
                ...$key,
                'on_hand' => '0',
                'reserved' => '0',
                'available' => '0',
                'inventory_value' => '0',
                'average_unit_cost' => '0',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $balance = StockBalance::query()->where($key)->lockForUpdate()->firstOrFail();
            $onHand = BigDecimal::of((string) $balance->on_hand);
            $amount = BigDecimal::of((string) $movement->base_quantity);
            if ($amount->isNegativeOrZero()) {
                throw ValidationException::withMessages(['base_quantity' => 'จำนวนฐานต้องมากกว่า 0']);
            }
            $onHand = $movement->direction === 'IN' ? $onHand->plus($amount) : $onHand->minus($amount);
            $reserved = BigDecimal::of((string) $balance->reserved);
            $available = $onHand->minus($reserved);
            if ($available->isNegative()) {
                throw ValidationException::withMessages(['stock' => 'ยอดคงเหลือไม่พอสำหรับ Movement นี้']);
            }
            $balance->update([
                'on_hand' => $onHand->toScale(8, RoundingMode::UNNECESSARY)->__toString(),
                'available' => $available->toScale(8, RoundingMode::UNNECESSARY)->__toString(),
            ]);

            return $balance->fresh();
        });
    }
}
