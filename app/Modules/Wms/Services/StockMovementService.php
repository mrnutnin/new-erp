<?php

namespace App\Modules\Wms\Services;

use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Jobs\RecalculateInventoryCost;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\BackdatedMovementGate;
use App\Modules\Wms\Support\StockMovementContract;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockMovementService
{
    public function recordIntent(array $attributes): StockMovement
    {
        try {
            $values = StockMovementContract::normalize($attributes);
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['movement' => $exception->getMessage()]);
        }
        $existing = StockMovement::query()->where('idempotency_key', $values['idempotency_key'])->first();
        if ($existing) {
            $comparable = ['warehouse_id', 'item_id', 'uom_id', 'movement_type', 'direction', 'quantity', 'base_quantity', 'business_date', 'source_type', 'source_id', 'source_reference', 'transfer_key'];
            if (collect($comparable)->contains(fn (string $key): bool => (string) $existing->{$key} !== (string) ($values[$key] ?? null))) {
                throw ValidationException::withMessages(['idempotency_key' => 'Idempotency key นี้ถูกใช้กับข้อมูลอื่นแล้ว']);
            }

            return $existing;
        }

        try {
            return StockMovement::query()->create($values);
        } catch (UniqueConstraintViolationException) {
            return StockMovement::query()->where('idempotency_key', $values['idempotency_key'])->firstOrFail();
        }
    }

    public function post(StockMovement $movement, ?StockBalanceProjectionService $projection = null): StockMovement
    {
        return DB::transaction(fn (): StockMovement => $this->postWithinTransaction($movement, $projection), 3);
    }

    /**
     * Join an already-open Purchase/Journal transaction. Callers own the
     * outer rollback boundary; this method deliberately does not start a
     * nested transaction.
     */
    public function postWithinTransaction(StockMovement $movement, ?StockBalanceProjectionService $projection = null): StockMovement
    {
        $movement = StockMovement::query()->lockForUpdate()->findOrFail($movement->id);
        if ($movement->status === 'POSTED') {
            return $movement;
        }
        if ($movement->status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'Movement นี้ไม่อยู่ในสถานะที่ Post ได้']);
        }
        app(BackdatedMovementGate::class)->assertOpen($movement->business_date);
        $method = app(GlobalSettings::class)->value('inventory_costing_method');
        $resolution = null;
        if ($movement->direction === 'OUT' && in_array($method, ['AVG', 'FIFO'], true)) {
            $balance = StockBalance::query()->firstOrCreate([
                'warehouse_id' => $movement->warehouse_id,
                'item_id' => $movement->item_id,
                'uom_id' => $movement->uom_id,
            ], ['on_hand' => '0', 'reserved' => '0', 'available' => '0', 'inventory_value' => '0', 'average_unit_cost' => '0']);
            $balance = StockBalance::query()->lockForUpdate()->findOrFail($balance->id);
            $resolution = app(StockCostPolicyResolver::class)->resolveIssue(
                (string) $balance->on_hand,
                (string) $movement->base_quantity,
                (string) $balance->average_unit_cost,
            );
        }
        if ($method === 'AVG') {
            app(StockCostLayerService::class)->applyAverageWithinTransaction($movement, $resolution);
        } elseif ($method === 'FIFO') {
            app(StockCostLayerService::class)->applyFifoWithinTransaction($movement, $resolution);
        } else {
            $movement->forceFill(['status' => 'POSTED', 'posted_at' => now()])->save();
            ($projection ?? app(StockBalanceProjectionService::class))->apply($movement);
        }

        if ($movement->status !== 'POSTED') {
            $movement->forceFill(['status' => 'POSTED', 'posted_at' => now()])->save();
        }
        if (($resolution['status'] ?? null) === 'PENDING') {
            app(StockRecostService::class)->createPending($movement, $resolution);
        }
        if ($movement->direction === 'IN') {
            $metadata = is_array($movement->metadata) ? $movement->metadata : [];
            $hasPendingRecost = app(StockRecostService::class)->requestIdsForReceipt($movement) !== [];
            if ($hasPendingRecost && ($metadata['unit_cost_trusted'] ?? false) === true && is_string($metadata['unit_cost'] ?? null)) {
                RecalculateInventoryCost::dispatch($movement->id, $metadata['unit_cost'])->afterCommit();
            }
        }

        return $movement->fresh();
    }

    /** Create an immutable reversal movement inside the caller's transaction. */
    public function reverseWithinTransaction(StockMovement $source, array $attributes): StockMovement
    {
        $source = StockMovement::query()->lockForUpdate()->findOrFail($source->id);
        if ($source->status !== 'POSTED') {
            throw ValidationException::withMessages(['status' => 'Reversal ทำได้เฉพาะ Posted Movement']);
        }
        $key = (string) ($attributes['idempotency_key'] ?? '');
        if ($key === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'ต้องมี reversal idempotency key']);
        }
        $existing = StockMovement::query()->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($existing) {
            foreach (['warehouse_id', 'item_id', 'uom_id', 'quantity', 'base_quantity', 'source_id'] as $field) {
                if ((string) $existing->{$field} !== (string) ($attributes[$field] ?? ($field === 'source_id' ? $source->id : $source->{$field}))) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Reversal key ถูกใช้กับข้อมูลคนละชุด']);
                }
            }

            if ($existing->status === 'POSTED') {
                return $existing;
            }
            if ($existing->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'Reversal movement เดิมอยู่ในสถานะที่ Retry ไม่ได้']);
            }

            return $this->postWithinTransaction($existing);
        }

        $metadata = is_array($source->metadata) ? $source->metadata : [];
        $direction = $source->direction === 'IN' ? 'OUT' : 'IN';

        // Reversal is a real inventory delta, not a marker row. Keep it DRAFT
        // until the normal costing path updates StockBalance/StockCostLayer and
        // creates the immutable allocation(s) in this same outer transaction.
        if ($direction === 'IN') {
            $sourceAllocations = CostAllocation::query()
                ->where('stock_movement_id', $source->id)
                ->where('status', '!=', 'REVERSED')
                ->where('cost_status', '!=', 'PENDING')
                ->lockForUpdate()->get();
            $quantity = BigDecimal::of((string) $source->base_quantity);
            $value = $sourceAllocations->reduce(
                fn (BigDecimal $sum, CostAllocation $allocation): BigDecimal => $sum->plus(BigDecimal::of((string) $allocation->value)->abs()),
                BigDecimal::zero(),
            );
            if ($quantity->isPositive() && $value->isPositive()) {
                $metadata['unit_cost'] = $value->dividedBy($quantity, 8, RoundingMode::HALF_UP)->__toString();
                $metadata['unit_cost_trusted'] = true;
            }
        }

        $reversal = StockMovement::query()->create([
            'warehouse_id' => $source->warehouse_id, 'item_id' => $source->item_id, 'uom_id' => $source->uom_id,
            'movement_type' => $source->movement_type, 'direction' => $direction,
            'status' => 'DRAFT', 'quantity' => $source->quantity, 'base_quantity' => $source->base_quantity,
            'business_date' => $attributes['business_date'] ?? $source->business_date,
            'source_type' => $source->source_type, 'source_id' => (string) $source->id,
            'source_reference' => $source->source_reference, 'idempotency_key' => $key,
            'metadata' => [...$metadata, 'reversal_of_movement_id' => $source->id, 'reversal_revision' => 1, 'reversal_parent_allocation_id' => $attributes['parent_allocation_id'] ?? null],
            'posted_at' => null, 'created_by' => $attributes['created_by'] ?? null,
        ]);

        return $this->postWithinTransaction($reversal);
    }
}
