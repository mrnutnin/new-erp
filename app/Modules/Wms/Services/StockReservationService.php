<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockReservation;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockReservationService
{
    public function reserve(array $attributes): StockReservation
    {
        return DB::transaction(function () use ($attributes): StockReservation {
            $quantity = BigDecimal::of((string) ($attributes['quantity'] ?? '0'))->toScale(8, RoundingMode::UNNECESSARY);
            if ($quantity->isNegativeOrZero()) {
                throw ValidationException::withMessages(['quantity' => 'จำนวนจองต้องมากกว่า 0']);
            }
            $existing = StockReservation::query()->where('idempotency_key', $attributes['idempotency_key'])->first();
            if ($existing) {
                if ((string) $existing->quantity !== $quantity->__toString()) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Idempotency key นี้ถูกใช้กับจำนวนอื่นแล้ว']);
                }

                return $existing;
            }
            $balance = StockBalance::query()->where(['warehouse_id' => $attributes['warehouse_id'], 'item_id' => $attributes['item_id'], 'uom_id' => $attributes['uom_id']])->lockForUpdate()->first();
            if (! $balance || BigDecimal::of((string) $balance->available)->isLessThan($quantity)) {
                throw ValidationException::withMessages(['quantity' => 'ยอด Available ไม่เพียงพอ']);
            }
            $reserved = BigDecimal::of((string) $balance->reserved)->plus($quantity);
            $balance->update(['reserved' => $reserved->toScale(8, RoundingMode::UNNECESSARY)->__toString(), 'available' => BigDecimal::of((string) $balance->available)->minus($quantity)->toScale(8, RoundingMode::UNNECESSARY)->__toString()]);

            return StockReservation::create([...$attributes, 'quantity' => $quantity->__toString(), 'status' => 'OPEN']);
        }, 3);
    }

    public function release(StockReservation $reservation): StockReservation
    {
        return DB::transaction(function () use ($reservation): StockReservation {
            $reservation = StockReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($reservation->status !== 'OPEN') {
                return $reservation;
            }
            $balance = StockBalance::query()->where(['warehouse_id' => $reservation->warehouse_id, 'item_id' => $reservation->item_id, 'uom_id' => $reservation->uom_id])->lockForUpdate()->firstOrFail();
            $quantity = BigDecimal::of((string) $reservation->quantity);
            $balance->update(['reserved' => BigDecimal::of((string) $balance->reserved)->minus($quantity)->toScale(8, RoundingMode::UNNECESSARY)->__toString(), 'available' => BigDecimal::of((string) $balance->available)->plus($quantity)->toScale(8, RoundingMode::UNNECESSARY)->__toString()]);
            $reservation->update(['status' => 'RELEASED']);

            return $reservation->fresh();
        }, 3);
    }
}
