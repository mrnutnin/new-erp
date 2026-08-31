<?php

namespace App\Modules\Wms\Services;

use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockCostLayer;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\CostingCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockCostLayerService
{
    public function __construct(private readonly GlobalSettings $settings, private readonly InventoryCostAllocationService $allocations) {}

    public function receive(StockMovement $movement, string $method, string $unitCost): StockCostLayer
    {
        if ($movement->status !== 'POSTED' || $movement->direction !== 'IN' || ! in_array($method, ['AVG', 'FIFO'], true)) {
            throw ValidationException::withMessages(['movement' => 'รับต้นทุนได้เฉพาะ Posted Movement ทิศทางเข้าและ policy AVG/FIFO']);
        }
        $configured = $this->settings->value('inventory_costing_method');
        if ($configured !== $method) {
            throw ValidationException::withMessages(['inventory_costing_method' => 'ต้องตั้งค่า Inventory costing method ให้ตรงกับรายการก่อนบันทึกต้นทุน']);
        }
        $cost = BigDecimal::of($unitCost)->toScale(8, RoundingMode::UNNECESSARY);
        if ($cost->isNegative()) {
            throw ValidationException::withMessages(['unit_cost' => 'ต้นทุนต้องไม่ติดลบ']);
        }

        return DB::transaction(fn () => StockCostLayer::firstOrCreate([
            'source_movement_id' => $movement->id,
        ], [
            'warehouse_id' => $movement->warehouse_id,
            'item_id' => $movement->item_id,
            'uom_id' => $movement->uom_id,
            'original_quantity' => $movement->base_quantity,
            'remaining_quantity' => $movement->base_quantity,
            'unit_cost' => $cost->__toString(),
            'method' => $method,
            'business_date' => $movement->business_date,
        ]));
    }

    public function issueFifo(int $warehouseId, int $itemId, int $uomId, string $quantity): array
    {
        if ($this->settings->value('inventory_costing_method') !== 'FIFO') {
            throw ValidationException::withMessages(['inventory_costing_method' => 'FIFO issue ใช้ได้เมื่อบริษัทตั้งค่า FIFO เท่านั้น']);
        }

        return DB::transaction(function () use ($warehouseId, $itemId, $uomId, $quantity): array {
            $layers = StockCostLayer::query()->where('warehouse_id', $warehouseId)->where('item_id', $itemId)->where('uom_id', $uomId)->where('method', 'FIFO')->where('cost_status', 'FINAL')->where('remaining_quantity', '>', 0)->orderBy('business_date')->orderBy('id')->lockForUpdate()->get();
            $allocations = CostingCalculator::fifoIssue($layers->map(fn ($layer) => ['quantity' => (string) $layer->remaining_quantity, 'unit_cost' => (string) $layer->unit_cost])->all(), $quantity);
            foreach ($allocations as $allocation) {
                $layer = $layers[$allocation['layer']];
                $layer->update(['remaining_quantity' => BigDecimal::of((string) $layer->remaining_quantity)->minus($allocation['quantity'])->toScale(8, RoundingMode::UNNECESSARY)->__toString()]);
            }

            return $allocations;
        }, 3);
    }

    /**
     * Apply one FIFO movement and its valuation atomically.
     * The caller marks the movement posted only after this succeeds.
     */
    public function applyFifo(StockMovement $movement, ?array $resolution = null): void
    {
        DB::transaction(function () use ($movement, $resolution): void {
            $this->applyFifoWithinTransaction($movement, $resolution);
        }, 3);
    }

    public function applyFifoWithinTransaction(StockMovement $movement, ?array $resolution = null): void
    {
        if ($this->settings->value('inventory_costing_method') !== 'FIFO') {
            throw ValidationException::withMessages(['inventory_costing_method' => 'FIFO posting ใช้ได้เมื่อบริษัทตั้งค่า FIFO เท่านั้น']);
        }

        $balance = StockBalance::query()->firstOrCreate([
            'warehouse_id' => $movement->warehouse_id,
            'item_id' => $movement->item_id,
            'uom_id' => $movement->uom_id,
        ], ['on_hand' => '0', 'reserved' => '0', 'available' => '0', 'inventory_value' => '0', 'average_unit_cost' => '0']);
        $balance = StockBalance::query()->lockForUpdate()->findOrFail($balance->id);
        $quantity = BigDecimal::of((string) $movement->base_quantity)->toScale(8, RoundingMode::UNNECESSARY);
        $onHand = BigDecimal::of((string) $balance->on_hand);
        $value = BigDecimal::of((string) $balance->inventory_value);
        $reserved = BigDecimal::of((string) $balance->reserved);

        if ($movement->direction === 'IN') {
            $unitCost = $this->trustedUnitCost($movement);
            $receiptValue = $quantity->multipliedBy(BigDecimal::of($unitCost));
            $layer = StockCostLayer::firstOrCreate(['source_movement_id' => $movement->id], [
                'warehouse_id' => $movement->warehouse_id, 'item_id' => $movement->item_id, 'uom_id' => $movement->uom_id,
                'original_quantity' => $this->out($quantity), 'remaining_quantity' => $this->out($quantity),
                'unit_cost' => $unitCost, 'method' => 'FIFO', 'business_date' => $movement->business_date,
            ]);
            $this->allocations->record($movement, 'FIFO', [
                'stock_cost_layer_id' => $layer->id, 'quantity' => $quantity, 'unit_cost' => $unitCost,
                'value' => $receiptValue, 'idempotency_key' => "movement:{$movement->id}:receipt", ...$this->reversalParent($movement),
            ]);
            $newOnHand = $onHand->plus($quantity);
            $newValue = $value->plus($receiptValue);
        } else {
            if ($quantity->isGreaterThan($onHand) && (($resolution['status'] ?? null) !== 'PENDING')) {
                throw ValidationException::withMessages(['stock' => 'FIFO layers มีจำนวนไม่พอสำหรับการจ่าย']);
            }
            $layers = StockCostLayer::query()->where('warehouse_id', $movement->warehouse_id)
                ->where('item_id', $movement->item_id)->where('uom_id', $movement->uom_id)
                ->where('method', 'FIFO')->where('cost_status', 'FINAL')->where('remaining_quantity', '>', 0)
                ->orderBy('business_date')->orderBy('id')->lockForUpdate()->get();
            try {
                $covered = $onHand->isNegative() ? BigDecimal::zero() : ($quantity->isGreaterThan($onHand) ? $onHand : $quantity);
                $allocations = $covered->isZero() ? [] : CostingCalculator::fifoIssue($layers->map(fn ($layer) => [
                    'quantity' => (string) $layer->remaining_quantity, 'unit_cost' => (string) $layer->unit_cost,
                ])->all(), $covered->__toString());
            } catch (\InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['stock' => $exception->getMessage()]);
            }
            $issuedValue = BigDecimal::zero();
            foreach ($allocations as $allocation) {
                $layer = $layers[$allocation['layer']];
                $remaining = BigDecimal::of((string) $layer->remaining_quantity)->minus($allocation['quantity']);
                $layer->update(['remaining_quantity' => $this->out($remaining)]);
                $issuedValue = $issuedValue->plus($allocation['value']);
                $this->allocations->record($movement, 'FIFO', [
                    'stock_cost_layer_id' => $layer->id, 'quantity' => $allocation['quantity'],
                    'unit_cost' => $allocation['unit_cost'], 'value' => '-'.$allocation['value'],
                    'idempotency_key' => "movement:{$movement->id}:issue:layer:{$layer->id}", ...$this->reversalParent($movement),
                ]);
            }
            if (($resolution['status'] ?? null) === 'PENDING') {
                $shortfall = BigDecimal::of((string) $resolution['shortfall_quantity']);
                $issuedValue = $issuedValue->plus($shortfall->multipliedBy(BigDecimal::of((string) $resolution['unit_cost'])));
                $this->allocations->record($movement, 'FIFO', [
                    'quantity' => $shortfall, 'unit_cost' => $resolution['unit_cost'],
                    'value' => '-'.$this->out($shortfall->multipliedBy(BigDecimal::of((string) $resolution['unit_cost']))),
                    'cost_status' => 'PENDING', 'idempotency_key' => "movement:{$movement->id}:issue:provisional", ...$this->reversalParent($movement),
                ], null);
            }
            $newOnHand = $onHand->minus($quantity);
            $newValue = $value->minus($issuedValue);
        }

        $available = $newOnHand->minus($reserved);
        if (($available->isNegative() || $newValue->isNegative()) && (($resolution['status'] ?? null) !== 'PENDING')) {
            throw ValidationException::withMessages(['stock' => 'ยอดคงเหลือหรือมูลค่าสินค้าติดลบ']);
        }
        $average = $newOnHand->isZero() ? BigDecimal::zero() : $newValue->dividedBy($newOnHand, 8, RoundingMode::HALF_UP);
        $balance->update([
            'on_hand' => $this->out($newOnHand), 'available' => $this->out($available),
            'inventory_value' => $this->out($newValue), 'average_unit_cost' => $this->out($average),
        ]);
    }

    /**
     * Apply one AVG movement and its valuation atomically.
     * The movement is marked posted by the caller after this succeeds.
     */
    public function applyAverage(StockMovement $movement, ?array $resolution = null): void
    {
        DB::transaction(function () use ($movement, $resolution): void {
            $this->applyAverageWithinTransaction($movement, $resolution);
        }, 3);
    }

    public function applyAverageWithinTransaction(StockMovement $movement, ?array $resolution = null): void
    {
        $unitCost = null;
        if ($movement->direction === 'IN') {
            $unitCost = $this->trustedUnitCost($movement);
        }

        if ($this->settings->value('inventory_costing_method') !== 'AVG') {
            throw ValidationException::withMessages(['inventory_costing_method' => 'AVG posting ใช้ได้เมื่อบริษัทตั้งค่า AVG เท่านั้น']);
        }

        $balance = StockBalance::query()->firstOrCreate([
            'warehouse_id' => $movement->warehouse_id,
            'item_id' => $movement->item_id,
            'uom_id' => $movement->uom_id,
        ], ['on_hand' => '0', 'reserved' => '0', 'available' => '0', 'inventory_value' => '0', 'average_unit_cost' => '0']);
        $balance = StockBalance::query()->lockForUpdate()->findOrFail($balance->id);
        $quantity = BigDecimal::of((string) $movement->base_quantity)->toScale(8, RoundingMode::UNNECESSARY);
        $onHand = BigDecimal::of((string) $balance->on_hand);
        $reserved = BigDecimal::of((string) $balance->reserved);
        $value = BigDecimal::of((string) $balance->inventory_value);
        $average = BigDecimal::of((string) $balance->average_unit_cost);

        if ($movement->direction === 'IN') {
            $result = CostingCalculator::average((string) $onHand, (string) $average, (string) $quantity, $unitCost);
            $newOnHand = BigDecimal::of($result['quantity']);
            $newValue = BigDecimal::of($result['value']);
            $newAverage = BigDecimal::of($result['unit_cost']);
            $layer = StockCostLayer::firstOrCreate(['source_movement_id' => $movement->id], [
                'warehouse_id' => $movement->warehouse_id, 'item_id' => $movement->item_id, 'uom_id' => $movement->uom_id,
                'original_quantity' => $quantity->__toString(), 'remaining_quantity' => $quantity->__toString(),
                'unit_cost' => $unitCost, 'method' => 'AVG', 'business_date' => $movement->business_date,
            ]);
            $this->allocations->record($movement, 'AVG', [
                'stock_cost_layer_id' => $layer->id, 'quantity' => $quantity, 'unit_cost' => $unitCost,
                'value' => $quantity->multipliedBy(BigDecimal::of($unitCost)), 'idempotency_key' => "movement:{$movement->id}:receipt", ...$this->reversalParent($movement),
            ]);
        } else {
            if ($quantity->isGreaterThan($onHand) && (($resolution['status'] ?? null) !== 'PENDING')) {
                throw ValidationException::withMessages(['stock' => 'ยอดคงเหลือไม่พอสำหรับ Movement นี้']);
            }
            $covered = $onHand->isNegative() ? BigDecimal::zero() : ($quantity->isGreaterThan($onHand) ? $onHand : $quantity);
            if ($covered->isZero()) {
                $newOnHand = $onHand;
                $newValue = $value;
            } else {
                $result = CostingCalculator::averageIssue((string) $onHand, (string) $average, (string) $covered);
                $newOnHand = BigDecimal::of($result['quantity']);
                $newValue = BigDecimal::of($result['value']);
                $this->allocations->record($movement, 'AVG', [
                    'quantity' => $covered, 'unit_cost' => $average,
                    'value' => '-'.$this->out($covered->multipliedBy($average)),
                    'idempotency_key' => "movement:{$movement->id}:issue", ...$this->reversalParent($movement),
                ]);
            }
            if (($resolution['status'] ?? null) === 'PENDING') {
                $shortfall = BigDecimal::of((string) $resolution['shortfall_quantity']);
                $newOnHand = $newOnHand->minus($shortfall);
                $newValue = $newValue->minus($shortfall->multipliedBy(BigDecimal::of((string) $resolution['unit_cost'])));
                $this->allocations->record($movement, 'AVG', [
                    'quantity' => $shortfall, 'unit_cost' => $resolution['unit_cost'],
                    'value' => '-'.$this->out($shortfall->multipliedBy(BigDecimal::of((string) $resolution['unit_cost']))),
                    'cost_status' => 'PENDING', 'idempotency_key' => "movement:{$movement->id}:issue:provisional", ...$this->reversalParent($movement),
                ]);
            }
            $newAverage = $newOnHand->isZero() ? BigDecimal::zero() : $newValue->dividedBy($newOnHand, 8, RoundingMode::HALF_UP);
        }
        $available = $newOnHand->minus($reserved);
        if (($available->isNegative() || $newValue->isNegative()) && (($resolution['status'] ?? null) !== 'PENDING')) {
            throw ValidationException::withMessages(['stock' => 'ยอดคงเหลือหรือมูลค่าสินค้าติดลบ']);
        }
        $balance->update([
            'on_hand' => $this->out($newOnHand), 'available' => $this->out($available),
            'inventory_value' => $this->out($newValue), 'average_unit_cost' => $this->out($newAverage),
        ]);
    }

    private function trustedUnitCost(StockMovement $movement): string
    {
        $metadata = is_array($movement->metadata) ? $movement->metadata : [];
        $value = $metadata['unit_cost'] ?? null;
        if (($metadata['unit_cost_trusted'] ?? false) !== true || ! is_string($value) || ! preg_match('/^\d+(?:\.\d{1,8})?$/', $value)) {
            throw ValidationException::withMessages(['unit_cost' => 'ต้องมี trusted unit cost จากเอกสารต้นทางก่อน Post Movement']);
        }

        return BigDecimal::of($value)->toScale(8, RoundingMode::UNNECESSARY)->__toString();
    }

    private function reversalParent(StockMovement $movement): array
    {
        $parent = (int) (((array) $movement->metadata)['reversal_parent_allocation_id'] ?? 0);

        return $parent > 0 ? ['parent_allocation_id' => $parent] : [];
    }

    private function out(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }
}
