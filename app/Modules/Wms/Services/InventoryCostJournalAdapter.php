<?php

namespace App\Modules\Wms\Services;

use App\Modules\Accounting\Models\Account;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\InventorySourceContract;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Builds the WMS payload accepted by JournalPostingService.
 *
 * This is intentionally an adapter, not a posting entry point. Inventory
 * posting stays closed until the source-document, reversal and reconciliation
 * gates are wired into one transaction. Keeping this payload here prevents a
 * future caller from falling back to the generic mapping account and losing
 * the item's explicit inventory/COGS account assignment.
 */
final class InventoryCostJournalAdapter
{
    /**
     * Build one sales COGS posting payload from an immutable final allocation.
     * No database query or JournalEntry is created by this method.
     */
    public function buildSalesCogsPayload(CostAllocation $allocation, StockMovement $movement, Item $item): array
    {
        $this->assertReady($allocation, $movement, $item);
        InventorySourceContract::assertCompatible($movement, $allocation, 'sales_cogs');

        // ISSUE/OUT allocations are inventory deltas, so WMS stores their
        // value as a negative amount. The accounting payload is the absolute
        // monetary effect: debit COGS and credit inventory.
        $amount = BigDecimal::of((string) $allocation->value)->abs()->toScale(2, RoundingMode::HALF_UP)->__toString();
        if (BigDecimal::of($amount)->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['allocation.value' => 'มูลค่า COGS หลังปัดเศษบัญชีต้องมากกว่าศูนย์']);
        }
        $inventory = $item->inventoryAccount;
        $cogs = $item->cogsAccount;

        return [
            'source_type' => 'INVENTORY',
            'source_id' => (string) $allocation->id,
            'source_reference' => $movement->source_reference ?: "WMS movement #{$movement->id}",
            'event_code' => 'sales_cogs',
            'entry_date' => $movement->business_date?->format('Y-m-d') ?: (string) $movement->business_date,
            'document_date' => $movement->business_date?->format('Y-m-d') ?: (string) $movement->business_date,
            'description' => "COGS จาก WMS movement #{$movement->id}",
            'posting_metadata' => [
                'contract_version' => 1,
                'event_code' => 'sales_cogs',
                'accounts' => [
                    ['event_code' => 'sales_cogs', 'account_role' => 'COGS', 'account_id' => (int) $cogs->id, 'source' => 'MASTER', 'source_type' => 'ITEM', 'source_id' => (string) $item->id, 'mapping_id' => null, 'mapping_version' => null],
                    ['event_code' => 'sales_cogs', 'account_role' => 'INVENTORY', 'account_id' => (int) $inventory->id, 'source' => 'MASTER', 'source_type' => 'ITEM', 'source_id' => (string) $item->id, 'mapping_id' => null, 'mapping_version' => null],
                ],
            ],
            'lines' => [
                [
                    'account_id' => (int) $cogs->id,
                    'subledger_type' => null,
                    'subledger_id' => null,
                    'description' => "COGS item #{$item->id}",
                    'debit' => $amount,
                    'credit' => '0.00',
                ],
                [
                    'account_id' => (int) $inventory->id,
                    'subledger_type' => 'ITEM',
                    'subledger_id' => (string) $item->id,
                    'description' => "ตัดสินค้าคงเหลือ item #{$item->id}",
                    'debit' => '0.00',
                    'credit' => $amount,
                ],
            ],
        ];
    }

    private function assertReady(CostAllocation $allocation, StockMovement $movement, Item $item): void
    {
        if ((string) $allocation->allocation_type !== 'ISSUE' || (string) $allocation->direction !== 'OUT') {
            throw ValidationException::withMessages(['allocation' => 'Sales COGS ต้องใช้ ISSUE/OUT allocation เท่านั้น']);
        }
        if ((string) $allocation->status !== 'PENDING' || (string) $allocation->cost_status !== 'FINAL') {
            throw ValidationException::withMessages(['allocation' => 'Allocation ต้องเป็น PENDING และมีต้นทุน FINAL ก่อนสร้าง Journal payload']);
        }
        if ($allocation->journal_entry_id !== null) {
            throw ValidationException::withMessages(['allocation' => 'Allocation นี้มี Journal แล้ว ห้ามสร้าง payload ซ้ำ']);
        }
        if ((string) $movement->status !== 'POSTED') {
            throw ValidationException::withMessages(['movement' => 'Movement ต้อง POSTED ก่อนสร้าง Journal payload']);
        }
        if ((int) $allocation->stock_movement_id !== (int) $movement->id || (int) $allocation->warehouse_id !== (int) $movement->warehouse_id || (int) $allocation->item_id !== (int) $movement->item_id || (int) $allocation->uom_id !== (int) $movement->uom_id || (string) $allocation->business_date !== (string) $movement->business_date || (int) $item->id !== (int) $movement->item_id) {
            throw ValidationException::withMessages(['allocation' => 'Allocation, Movement และ Item ต้องอ้างอิงชุดข้อมูลเดียวกัน']);
        }
        if (! BigDecimal::of((string) $allocation->value)->isNegative()) {
            throw ValidationException::withMessages(['allocation.value' => 'ISSUE/OUT allocation ต้องเก็บมูลค่าต้นทุนเป็นค่าติดลบ']);
        }

        $this->assertInventoryAccount($item->inventoryAccount);
        $this->assertCogsAccount($item->cogsAccount);
    }

    private function assertInventoryAccount(?Account $account): void
    {
        if (! $account || ! $account->is_active || ! $account->is_postable || $account->control_account_type !== 'INVENTORY') {
            throw ValidationException::withMessages(['inventory_account' => 'บัญชี Inventory ของสินค้าต้อง active, postable และเป็นบัญชีคุม INVENTORY']);
        }
    }

    private function assertCogsAccount(?Account $account): void
    {
        if (! $account || ! $account->is_active || ! $account->is_postable || $account->control_account_type !== null || $account->type?->code !== 'EXPENSE') {
            throw ValidationException::withMessages(['cogs_account' => 'บัญชี COGS ของสินค้าต้อง active, postable และเป็นบัญชีประเภท EXPENSE ที่ไม่ใช่บัญชีคุม']);
        }
    }
}
