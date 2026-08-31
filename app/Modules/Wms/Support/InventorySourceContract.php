<?php

namespace App\Modules\Wms\Support;

use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\StockMovement;
use Illuminate\Validation\ValidationException;

/**
 * Validates the source boundary before an inventory allocation can be handed
 * to an accounting adapter. This is deliberately read-only: it does not look
 * up source documents and it never creates or changes a JournalEntry.
 */
final class InventorySourceContract
{
    public static function assertCompatible(StockMovement $movement, CostAllocation $allocation, string $eventCode): void
    {
        $eventCode = strtolower(trim($eventCode));
        $expected = match ($eventCode) {
            'inventory.receipt' => [
                'movement_types' => ['RECEIPT'],
                'direction' => 'IN',
                'source_types' => ['INVENTORY', 'PURCHASING'],
            ],
            'sales_cogs' => [
                'movement_types' => ['ISSUE'],
                'direction' => 'OUT',
                'source_types' => ['POS'],
            ],
            'inventory.adjustment' => [
                'movement_types' => ['ADJUSTMENT', 'COUNT'],
                'direction' => null,
                'source_types' => ['INVENTORY'],
            ],
            default => throw ValidationException::withMessages(['event_code' => 'ไม่รองรับ Inventory source event นี้']),
        };

        if (! in_array((string) $movement->movement_type, $expected['movement_types'], true)) {
            throw ValidationException::withMessages(['movement_type' => 'ชนิด Stock movement ไม่ตรงกับ Inventory event']);
        }
        if ($expected['direction'] !== null && (string) $movement->direction !== $expected['direction']) {
            throw ValidationException::withMessages(['direction' => 'ทิศทาง Stock movement ไม่ตรงกับ Inventory event']);
        }

        foreach (['source_type', 'source_id', 'source_reference'] as $field) {
            if (! filled(trim((string) $movement->{$field}))) {
                throw ValidationException::withMessages([$field => 'Stock movement ต้องมี source identity ครบก่อนตรวจ Inventory→GL']);
            }
        }
        if (! in_array(strtoupper((string) $movement->source_type), $expected['source_types'], true)) {
            throw ValidationException::withMessages(['source_type' => 'Source type ไม่ตรงกับ Inventory event']);
        }

        $sameMovement = (int) $allocation->stock_movement_id === (int) $movement->id;
        $sameWarehouse = (int) $allocation->warehouse_id === (int) $movement->warehouse_id;
        $sameItem = (int) $allocation->item_id === (int) $movement->item_id;
        $sameUom = (int) $allocation->uom_id === (int) $movement->uom_id;
        $sameDate = (string) $allocation->business_date === (string) $movement->business_date;
        if (! $sameMovement || ! $sameWarehouse || ! $sameItem || ! $sameUom || ! $sameDate) {
            throw ValidationException::withMessages(['allocation' => 'Cost allocation ต้องอ้าง Stock movement, warehouse, item, UOM และวันที่เดียวกัน']);
        }
    }
}
