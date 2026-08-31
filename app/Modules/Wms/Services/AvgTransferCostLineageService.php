<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockCostLayer;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Models\Transfer;
use App\Modules\Wms\Models\TransferLine;
use App\Modules\Wms\Support\BackdatedMovementGate;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** AVG transfer adapter; preserves source allocation/layer lineage without GL. */
final class AvgTransferCostLineageService
{
    public function __construct(
        private readonly StockMovementService $movements,
        private readonly InventoryCostAllocationService $allocations,
        private readonly GlobalSettings $settings,
    ) {}

    public function inbound(TransferLine $line, Transfer $transfer, string $quantity, string $baseQuantity, string $commandKey, User $actor, int $warehouseId, string $eventType, ?string $businessDate = null): StockMovement
    {
        if ($this->settings->value('inventory_costing_method') !== 'AVG') {
            throw ValidationException::withMessages(['inventory_costing_method' => 'Transfer AVG adapter ใช้ได้เมื่อ policy เป็น AVG เท่านั้น']);
        }
        if (! in_array($eventType, ['ACCEPT', 'REJECT'], true)) {
            throw ValidationException::withMessages(['event_type' => 'Transfer AVG lineage รองรับเฉพาะ Accept/Reject']);
        }

        return DB::transaction(function () use ($line, $transfer, $quantity, $baseQuantity, $commandKey, $actor, $warehouseId, $eventType, $businessDate): StockMovement {
            $source = StockMovement::query()->where('source_type', 'WMS_TRANSFER')->where('source_id', (string) $transfer->id)->where('transfer_key', "transfer:{$transfer->id}:line:{$line->id}")->where('direction', 'OUT')->where('status', 'POSTED')->lockForUpdate()->firstOrFail();
            $targetWarehouse = $eventType === 'ACCEPT' ? $transfer->destination_warehouse_id : $transfer->source_warehouse_id;
            if ((int) $targetWarehouse !== $warehouseId) {
                throw ValidationException::withMessages(['warehouse' => 'Warehouse context ไม่ตรงกับปลายทางของ AVG transfer']);
            }
            $date = $businessDate ?: $transfer->document_date->format('Y-m-d');
            app(BackdatedMovementGate::class)->assertOpen($date);
            $key = "transfer:{$transfer->id}:command:{$commandKey}:line:{$line->id}:".strtolower($eventType);
            $movement = $this->movements->recordIntent([
                'warehouse_id' => $targetWarehouse, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id,
                'movement_type' => 'TRANSFER', 'direction' => 'IN', 'status' => 'DRAFT', 'quantity' => $quantity, 'base_quantity' => $baseQuantity,
                'business_date' => $date, 'source_type' => 'WMS_TRANSFER', 'source_id' => (string) $transfer->id,
                'source_reference' => $transfer->document_number, 'transfer_key' => "transfer:{$transfer->id}:line:{$line->id}",
                'idempotency_key' => $key.':movement', 'metadata' => ['transfer_id' => $transfer->id, 'transfer_line_id' => $line->id, 'transfer_event' => $eventType, 'avg_lineage' => true, 'source_movement_id' => $source->id],
                'created_by' => $actor->id,
            ]);
            $movement = StockMovement::query()->lockForUpdate()->findOrFail($movement->id);
            if ($movement->status === 'POSTED') {
                return $movement;
            }
            if ($movement->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'AVG transfer movement อยู่ในสถานะที่ retry ไม่ได้']);
            }

            $sourceAllocations = CostAllocation::query()->where('stock_movement_id', $source->id)->where('status', '!=', 'REVERSED')->where('cost_status', 'FINAL')->orderBy('id')->lockForUpdate()->get();
            $used = CostAllocation::query()->where('allocation_type', 'TRANSFER')->where('direction', 'IN')->where('status', '!=', 'REVERSED')->whereHas('movement', function (Builder $query) use ($transfer, $line): void {
                $query->where('source_type', 'WMS_TRANSFER')->where('source_id', (string) $transfer->id)->where('metadata->transfer_line_id', $line->id);
            })->get(['parent_allocation_id', 'quantity'])->groupBy('parent_allocation_id')->map(fn ($rows): BigDecimal => $rows->reduce(fn (BigDecimal $sum, CostAllocation $allocation): BigDecimal => $sum->plus(BigDecimal::of((string) $allocation->quantity)), BigDecimal::zero()));
            $remaining = BigDecimal::of($baseQuantity);
            $value = BigDecimal::zero();
            if ($sourceAllocations->isEmpty()) {
                throw ValidationException::withMessages(['cost_lineage' => 'ไม่พบ AVG source allocation ที่เป็นต้นทุน final']);
            }
            $balance = StockBalance::query()->firstOrCreate(['warehouse_id' => $targetWarehouse, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id], ['on_hand' => '0', 'reserved' => '0', 'available' => '0', 'inventory_value' => '0', 'average_unit_cost' => '0']);
            $balance = StockBalance::query()->lockForUpdate()->findOrFail($balance->id);
            foreach ($sourceAllocations as $sourceAllocation) {
                if ($remaining->isZero()) {
                    break;
                }
                $available = BigDecimal::of((string) $sourceAllocation->quantity)->minus($used->get($sourceAllocation->id, BigDecimal::zero()));
                if ($available->isLessThanOrEqualTo(0)) {
                    continue;
                }
                $take = $available->isGreaterThan($remaining) ? $remaining : $available;
                $unitCost = BigDecimal::of((string) $sourceAllocation->unit_cost)->toScale(8, RoundingMode::UNNECESSARY);
                $lineageKey = "transfer:{$movement->id}:source-allocation:{$sourceAllocation->id}";
                $parentLayerId = $sourceAllocation->stock_cost_layer_id ?: StockCostLayer::query()->where('warehouse_id', $source->warehouse_id)->where('item_id', $line->item_id)->where('uom_id', $line->uom_id)->where('method', 'AVG')->orderByDesc('id')->value('id');
                $layer = StockCostLayer::query()->firstOrCreate(['lineage_key' => $lineageKey], [
                    'warehouse_id' => $targetWarehouse, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'source_movement_id' => $movement->id,
                    'parent_layer_id' => $parentLayerId, 'original_quantity' => $take, 'remaining_quantity' => $take,
                    'unit_cost' => $unitCost, 'method' => 'AVG', 'cost_status' => 'FINAL', 'business_date' => $movement->business_date,
                ]);
                $allocation = $this->allocations->record($movement, 'AVG', [
                    'stock_cost_layer_id' => $layer->id, 'parent_allocation_id' => $sourceAllocation->id, 'allocation_type' => 'TRANSFER', 'direction' => 'IN',
                    'cost_status' => 'FINAL', 'quantity' => $take, 'unit_cost' => $unitCost, 'value' => $take->multipliedBy($unitCost),
                    'idempotency_key' => "transfer:{$movement->id}:source-allocation:{$sourceAllocation->id}",
                    'metadata' => ['source_allocation_id' => $sourceAllocation->id, 'source_layer_id' => $sourceAllocation->stock_cost_layer_id, 'lineage_key' => $lineageKey],
                ]);
                if ($allocation->status !== 'POSTED') {
                    $allocation->forceFill(['status' => 'POSTED'])->save();
                }
                $value = $value->plus($take->multipliedBy($unitCost));
                $remaining = $remaining->minus($take);
            }
            if ($remaining->isPositive()) {
                throw ValidationException::withMessages(['quantity' => 'จำนวน Transfer เกิน AVG source allocation ที่พร้อมย้าย']);
            }
            $onHand = BigDecimal::of((string) $balance->on_hand)->plus(BigDecimal::of($baseQuantity));
            $inventoryValue = BigDecimal::of((string) $balance->inventory_value)->plus($value);
            $reserved = BigDecimal::of((string) $balance->reserved);
            $balance->forceFill(['on_hand' => $this->out($onHand), 'inventory_value' => $this->out($inventoryValue), 'available' => $this->out($onHand->minus($reserved)), 'average_unit_cost' => $onHand->isZero() ? '0.00000000' : $this->out($inventoryValue->dividedBy($onHand, 8, RoundingMode::HALF_UP))])->save();
            $movement->forceFill(['status' => 'POSTED', 'posted_at' => now()])->save();

            return $movement->fresh();
        }, 3);
    }

    private function out(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }
}
