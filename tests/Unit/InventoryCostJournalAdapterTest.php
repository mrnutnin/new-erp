<?php

namespace Tests\Unit;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Services\InventoryCostJournalAdapter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryCostJournalAdapterTest extends TestCase
{
    public function test_builds_item_specific_sales_cogs_payload_without_posting(): void
    {
        $expenseType = (new AccountType(['code' => 'EXPENSE']))->forceFill(['id' => 8]);
        $cogs = (new Account(['is_active' => true, 'is_postable' => true, 'control_account_type' => null]))->forceFill(['id' => 10])->setRelation('type', $expenseType);
        $inventory = (new Account(['is_active' => true, 'is_postable' => true, 'control_account_type' => 'INVENTORY']))->forceFill(['id' => 20]);
        $item = (new Item)->forceFill(['id' => 4])->setRelation('inventoryAccount', $inventory)->setRelation('cogsAccount', $cogs);
        $movement = (new StockMovement(['warehouse_id' => 7, 'item_id' => 4, 'uom_id' => 2, 'status' => 'POSTED', 'business_date' => '2026-08-21', 'source_type' => 'POS', 'source_id' => 'sale-9', 'source_reference' => 'SO-001', 'movement_type' => 'ISSUE', 'direction' => 'OUT']))->forceFill(['id' => 9]);
        $allocation = (new CostAllocation(['stock_movement_id' => 9, 'warehouse_id' => 7, 'item_id' => 4, 'uom_id' => 2, 'business_date' => '2026-08-21', 'allocation_type' => 'ISSUE', 'direction' => 'OUT', 'status' => 'PENDING', 'cost_status' => 'FINAL', 'value' => '-12.345']))->forceFill(['id' => 11]);

        $payload = (new InventoryCostJournalAdapter)->buildSalesCogsPayload($allocation, $movement, $item);

        $this->assertSame('sales_cogs', $payload['event_code']);
        $this->assertSame(10, $payload['lines'][0]['account_id']);
        $this->assertSame(20, $payload['lines'][1]['account_id']);
        $this->assertSame('ITEM', $payload['lines'][1]['subledger_type']);
        $this->assertSame('4', $payload['lines'][1]['subledger_id']);
        $this->assertSame('12.35', $payload['lines'][0]['debit']);
        $this->assertSame('12.35', $payload['lines'][1]['credit']);
    }

    public function test_rejects_missing_item_specific_cogs_account(): void
    {
        $inventory = (new Account(['is_active' => true, 'is_postable' => true, 'control_account_type' => 'INVENTORY']))->forceFill(['id' => 20]);
        $item = (new Item)->forceFill(['id' => 4])->setRelation('inventoryAccount', $inventory)->setRelation('cogsAccount', null);
        $movement = (new StockMovement(['warehouse_id' => 7, 'item_id' => 4, 'uom_id' => 2, 'status' => 'POSTED', 'business_date' => '2026-08-21', 'source_type' => 'POS', 'source_id' => 'sale-9', 'source_reference' => 'SO-001', 'movement_type' => 'ISSUE', 'direction' => 'OUT']))->forceFill(['id' => 9]);
        $allocation = (new CostAllocation(['stock_movement_id' => 9, 'warehouse_id' => 7, 'item_id' => 4, 'uom_id' => 2, 'business_date' => '2026-08-21', 'allocation_type' => 'ISSUE', 'direction' => 'OUT', 'status' => 'PENDING', 'cost_status' => 'FINAL', 'value' => '12.00']))->forceFill(['id' => 11]);

        $this->expectException(ValidationException::class);
        (new InventoryCostJournalAdapter)->buildSalesCogsPayload($allocation, $movement, $item);
    }

    public function test_rejects_a_non_negative_issue_allocation(): void
    {
        $expenseType = (new AccountType(['code' => 'EXPENSE']))->forceFill(['id' => 8]);
        $cogs = (new Account(['is_active' => true, 'is_postable' => true, 'control_account_type' => null]))->forceFill(['id' => 10])->setRelation('type', $expenseType);
        $inventory = (new Account(['is_active' => true, 'is_postable' => true, 'control_account_type' => 'INVENTORY']))->forceFill(['id' => 20]);
        $item = (new Item)->forceFill(['id' => 4])->setRelation('inventoryAccount', $inventory)->setRelation('cogsAccount', $cogs);
        $movement = (new StockMovement(['warehouse_id' => 7, 'item_id' => 4, 'uom_id' => 2, 'status' => 'POSTED', 'business_date' => '2026-08-21', 'source_type' => 'POS', 'source_id' => 'sale-9', 'source_reference' => 'SO-001', 'movement_type' => 'ISSUE', 'direction' => 'OUT']))->forceFill(['id' => 9]);
        $allocation = (new CostAllocation(['stock_movement_id' => 9, 'warehouse_id' => 7, 'item_id' => 4, 'uom_id' => 2, 'business_date' => '2026-08-21', 'allocation_type' => 'ISSUE', 'direction' => 'OUT', 'status' => 'PENDING', 'cost_status' => 'FINAL', 'value' => '12.00']))->forceFill(['id' => 11]);

        $this->expectException(ValidationException::class);
        (new InventoryCostJournalAdapter)->buildSalesCogsPayload($allocation, $movement, $item);
    }
}
