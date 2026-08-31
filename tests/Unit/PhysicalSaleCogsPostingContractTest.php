<?php

namespace Tests\Unit;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountType;
use App\Modules\Pos\Support\PhysicalSaleCogsPostingContract;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockMovement;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PhysicalSaleCogsPostingContractTest extends TestCase
{
    public function test_builds_cogs_payload_only_for_the_same_pos_sale(): void
    {
        $expense = (new AccountType(['code' => 'EXPENSE']))->forceFill(['id' => 1]);
        $cogs = (new Account(['id' => 10, 'is_active' => true, 'is_postable' => true, 'control_account_type' => null]))->setRelation('type', $expense);
        $inventory = new Account(['id' => 20, 'is_active' => true, 'is_postable' => true, 'control_account_type' => 'INVENTORY']);
        $item = (new Item)->forceFill(['id' => 4])->setRelation('cogsAccount', $cogs)->setRelation('inventoryAccount', $inventory);
        $movement = (new StockMovement(['id' => 9, 'warehouse_id' => 7, 'item_id' => 4, 'uom_id' => 2, 'status' => 'POSTED', 'business_date' => '2026-08-21', 'source_type' => 'POS', 'source_id' => '9', 'source_reference' => 'HS-001', 'movement_type' => 'ISSUE', 'direction' => 'OUT']))->forceFill(['id' => 9]);
        $allocation = new CostAllocation(['stock_movement_id' => 9, 'warehouse_id' => 7, 'item_id' => 4, 'uom_id' => 2, 'business_date' => '2026-08-21', 'allocation_type' => 'ISSUE', 'direction' => 'OUT', 'status' => 'PENDING', 'cost_status' => 'FINAL', 'value' => '-12.35']);
        $allocation->forceFill(['id' => 11]);

        $result = PhysicalSaleCogsPostingContract::build(['id' => 9, 'document_number' => 'HS-001'], $allocation, $movement, $item);

        $this->assertSame(9, $result['sale_id']);
        $this->assertSame('sales_cogs', $result['payload']['event_code']);
        $this->assertSame('12.35', $result['payload']['lines'][0]['debit']);
    }

    public function test_rejects_movement_from_another_sale(): void
    {
        $movement = new StockMovement(['source_type' => 'POS', 'source_id' => '8', 'source_reference' => 'HS-OTHER']);

        $this->expectException(ValidationException::class);
        PhysicalSaleCogsPostingContract::build(['id' => 9, 'document_number' => 'HS-001'], new CostAllocation, $movement, new Item);
    }
}
