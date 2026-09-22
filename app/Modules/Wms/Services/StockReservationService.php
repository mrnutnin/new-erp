<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Models\StockReservation;
use App\Modules\Wms\Models\StockReservationConsumption;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockReservationService
{
    public function reserve(array $attributes): StockReservation
    {
        return DB::transaction(function () use ($attributes): StockReservation {
            $quantity = $this->quantity($attributes['quantity'] ?? '0');
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
            $balance->update(['reserved' => $this->out($reserved), 'available' => $this->out(BigDecimal::of((string) $balance->available)->minus($quantity))]);

            return StockReservation::create([...$attributes, 'quantity' => $quantity->__toString(), 'consumed_quantity' => '0.00000000', 'status' => 'OPEN']);
        }, 3);
    }

    /**
     * Consume a reservation and post its matching OUT movement atomically.
     * The callback must return the posted movement; any failure rolls back both operations.
     */
    public function consume(StockReservation $reservation, string $quantity, StockMovement $movement, callable $postMovement): StockReservation
    {
        return DB::transaction(function () use ($reservation, $quantity, $movement, $postMovement): StockReservation {
            $reservation = StockReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $movement = StockMovement::query()->lockForUpdate()->findOrFail($movement->id);
            $amount = $this->quantity($quantity);
            $existing = StockReservationConsumption::query()->where('stock_movement_id', $movement->id)->first();
            if ($existing) {
                if ((int) $existing->stock_reservation_id !== (int) $reservation->id || BigDecimal::of((string) $existing->quantity)->compareTo($amount) !== 0) {
                    throw ValidationException::withMessages(['movement' => 'Movement นี้ถูกใช้กับรายการจองอื่นหรือจำนวนอื่นแล้ว']);
                }

                return $reservation;
            }

            $consumed = BigDecimal::of((string) $reservation->consumed_quantity);
            $remaining = BigDecimal::of((string) $reservation->quantity)->minus($consumed);
            if ($reservation->status !== 'OPEN' || $amount->isGreaterThan($remaining)) {
                throw ValidationException::withMessages(['quantity' => 'จำนวนใช้เกินยอดจองคงเหลือหรือรายการจองปิดแล้ว']);
            }
            if ($movement->status !== 'DRAFT' || $movement->direction !== 'OUT'
                || (int) $movement->warehouse_id !== (int) $reservation->warehouse_id
                || (int) $movement->item_id !== (int) $reservation->item_id
                || (int) $movement->uom_id !== (int) $reservation->uom_id
                || BigDecimal::of((string) $movement->base_quantity)->compareTo($amount) !== 0) {
                throw ValidationException::withMessages(['movement' => 'Movement ที่ใช้ยอดจองต้องเป็น OUT Draft และตรงกับคลัง สินค้า หน่วย และจำนวน']);
            }

            $balance = StockBalance::query()->where([
                'warehouse_id' => $reservation->warehouse_id,
                'item_id' => $reservation->item_id,
                'uom_id' => $reservation->uom_id,
            ])->lockForUpdate()->firstOrFail();
            $reserved = BigDecimal::of((string) $balance->reserved);
            if ($amount->isGreaterThan($reserved)) {
                throw ValidationException::withMessages(['quantity' => 'ยอด Reserved ใน Stock Balance ไม่เพียงพอ']);
            }

            // Make the consumed portion available inside this transaction so the
            // normal stock posting service can issue it and restore on_hand-reserved.
            $balance->update([
                'reserved' => $this->out($reserved->minus($amount)),
                'available' => $this->out(BigDecimal::of((string) $balance->available)->plus($amount)),
            ]);

            $postedMovement = $postMovement($movement);
            if (! $postedMovement instanceof StockMovement || (int) $postedMovement->id !== (int) $movement->id || $postedMovement->status !== 'POSTED') {
                throw ValidationException::withMessages(['movement' => 'Stock posting callback ต้องคืน Movement เดิมที่ Post สำเร็จแล้ว']);
            }

            StockReservationConsumption::query()->create([
                'stock_reservation_id' => $reservation->id,
                'stock_movement_id' => $movement->id,
                'quantity' => $amount->__toString(),
            ]);

            $newConsumed = $consumed->plus($amount);
            $reservation->update([
                'consumed_quantity' => $this->out($newConsumed),
                'status' => $newConsumed->compareTo(BigDecimal::of((string) $reservation->quantity)) === 0 ? 'CONSUMED' : 'OPEN',
            ]);

            return $reservation->fresh();
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
            $quantity = BigDecimal::of((string) $reservation->quantity)->minus(BigDecimal::of((string) $reservation->consumed_quantity));
            $balance->update(['reserved' => $this->out(BigDecimal::of((string) $balance->reserved)->minus($quantity)), 'available' => $this->out(BigDecimal::of((string) $balance->available)->plus($quantity))]);
            $reservation->update(['status' => 'RELEASED']);

            return $reservation->fresh();
        }, 3);
    }

    private function quantity(mixed $quantity): BigDecimal
    {
        $amount = BigDecimal::of((string) $quantity)->toScale(8, RoundingMode::UNNECESSARY);
        if ($amount->isNegativeOrZero()) {
            throw ValidationException::withMessages(['quantity' => 'จำนวนต้องมากกว่า 0']);
        }

        return $amount;
    }

    private function out(BigDecimal $quantity): string
    {
        return $quantity->toScale(8, RoundingMode::UNNECESSARY)->__toString();
    }
}
