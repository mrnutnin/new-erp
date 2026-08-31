<?php

namespace App\Modules\Wms\Services;

use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Wms\Models\CostAllocation;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * Validates the inventory-to-GL boundary without creating a JournalEntry.
 * Posting remains intentionally deferred until source-account and reconciliation contracts exist.
 */
final class InventoryCostPostingContract
{
    /**
     * Validate the non-account context before a caller builds a posting
     * payload. This remains read-only and prevents cross-warehouse/source
     * leakage at the Inventory → GL boundary.
     */
    public function assertContext(CostAllocation $allocation, int $warehouseId, ?string $sourceType = null, ?string $sourceId = null): void
    {
        if ((int) $allocation->warehouse_id !== $warehouseId) {
            throw ValidationException::withMessages(['warehouse_id' => 'Cost allocation ไม่อยู่ใน Warehouse scope เดียวกับการ Post']);
        }

        if ($sourceType !== null && trim((string) $allocation->metadata['source_type'] ?? '') !== trim($sourceType)) {
            throw ValidationException::withMessages(['source_type' => 'Source ของ Cost allocation ไม่ตรงกับ Inventory event']);
        }

        if ($sourceId !== null && trim((string) $allocation->metadata['source_id'] ?? '') !== trim($sourceId)) {
            throw ValidationException::withMessages(['source_id' => 'Source ID ของ Cost allocation ไม่ตรงกับ Inventory event']);
        }
    }

    public function requirements(CostAllocation $allocation, string $eventCode): array
    {
        $eventCode = strtolower(trim($eventCode));
        $type = (string) $allocation->allocation_type;
        $direction = (string) $allocation->direction;

        $valid = match ($eventCode) {
            'inventory.receipt' => $type === 'RECEIPT' && $direction === 'IN',
            'sales_cogs' => $type === 'ISSUE' && $direction === 'OUT',
            'inventory.adjustment' => $type === 'ADJUSTMENT' && in_array($direction, ['IN', 'OUT'], true),
            'inventory.recost' => $type === 'RECOST',
            default => false,
        };

        if (! $valid) {
            throw ValidationException::withMessages(['event_code' => 'ประเภท allocation ไม่ตรงกับ Inventory event ที่ร้องขอ']);
        }
        if ((string) $allocation->status !== 'PENDING') {
            throw ValidationException::withMessages(['status' => 'รองรับ dry-run เฉพาะ allocation ที่รอ Post เท่านั้น']);
        }
        // The scalar linkage is the canonical idempotency guard at this boundary.
        // Do not query the optional linkage table during a pure contract check:
        // callers may run dry-run against a schema where that audit table is not
        // installed yet, while a non-null journal_entry_id is always persisted
        // on the allocation itself once posting has occurred.
        if ($allocation->journal_entry_id !== null) {
            throw ValidationException::withMessages(['journal_entry_id' => 'Allocation นี้มี Journal linkage แล้ว ห้ามสร้าง dry-run ซ้ำ']);
        }
        if ((string) $allocation->cost_status !== 'FINAL') {
            throw ValidationException::withMessages(['cost_status' => 'ต้นทุน provisional ต้องรอ RECOST ก่อนจึงตรวจ Post ได้']);
        }
        if (BigDecimal::of((string) $allocation->value)->isNegative() || BigDecimal::of((string) $allocation->value)->isZero()) {
            throw ValidationException::withMessages(['value' => 'มูลค่า allocation ต้องมากกว่าศูนย์ใน dry-run']);
        }

        return [
            'event_code' => $eventCode,
            'allocation_id' => $allocation->id,
            'warehouse_id' => $allocation->warehouse_id,
            'item_id' => $allocation->item_id,
            'mapping_keys' => match ($eventCode) {
                'inventory.receipt' => ['INVENTORY_DEFAULT'],
                'sales_cogs' => ['COGS_DEFAULT', 'INVENTORY_DEFAULT'],
                'inventory.adjustment' => [
                    'INVENTORY_DEFAULT',
                    $direction === 'IN' ? 'INVENTORY_ADJUSTMENT_GAIN' : 'INVENTORY_ADJUSTMENT_LOSS',
                ],
                'inventory.recost' => ['INVENTORY_REVALUATION'],
            },
            'requires_source_account' => $eventCode === 'inventory.receipt',
            'creates_journal' => false,
        ];
    }

    public function dryRun(CostAllocation $allocation, string $eventCode, AccountMappingService $mappings): array
    {
        $requirements = $this->requirements($allocation, $eventCode);
        $accounts = [];
        foreach ($requirements['mapping_keys'] as $key) {
            $accounts[$key] = $mappings->resolve($key)->id;
        }

        return [...$requirements, 'accounts' => $accounts];
    }
}
