<?php

namespace Tests\Unit;

use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Services\InventoryCostPostingContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryCostPostingContractTest extends TestCase
{
    public function test_context_gate_rejects_cross_warehouse_and_mismatched_source(): void
    {
        $contract = new InventoryCostPostingContract;
        $allocation = new CostAllocation(['warehouse_id' => 7, 'metadata' => ['source_type' => 'PURCHASING', 'source_id' => '42']]);

        $this->expectException(ValidationException::class);
        $contract->assertContext($allocation, 8, 'PURCHASING', '42');
    }

    public function test_sales_cogs_requires_final_issue_allocation_and_typed_mappings(): void
    {
        $allocation = new CostAllocation([
            'id' => 9, 'allocation_type' => 'ISSUE', 'direction' => 'OUT', 'status' => 'PENDING',
            'cost_status' => 'FINAL', 'value' => '125.00', 'warehouse_id' => 2, 'item_id' => 7,
        ]);

        $result = (new InventoryCostPostingContract)->requirements($allocation, 'sales_cogs');

        $this->assertSame(['COGS_DEFAULT', 'INVENTORY_DEFAULT'], $result['mapping_keys']);
        $this->assertFalse($result['creates_journal']);
    }

    public function test_provisional_or_wrong_event_is_rejected(): void
    {
        $allocation = new CostAllocation([
            'allocation_type' => 'ISSUE', 'direction' => 'OUT', 'status' => 'PENDING',
            'cost_status' => 'PENDING', 'value' => '125.00',
        ]);

        $this->expectException(ValidationException::class);
        (new InventoryCostPostingContract)->requirements($allocation, 'sales_cogs');
    }

    public function test_journal_linked_allocation_is_rejected_from_dry_run(): void
    {
        $allocation = new CostAllocation([
            'allocation_type' => 'ISSUE', 'direction' => 'OUT', 'status' => 'PENDING',
            'cost_status' => 'FINAL', 'value' => '125.00', 'journal_entry_id' => 88,
        ]);

        $this->expectException(ValidationException::class);
        (new InventoryCostPostingContract)->requirements($allocation, 'sales_cogs');
    }

    public function test_inventory_adjustment_resolves_inventory_and_directional_gain_or_loss_mappings(): void
    {
        $contract = new InventoryCostPostingContract;
        $base = [
            'allocation_type' => 'ADJUSTMENT', 'status' => 'PENDING',
            'cost_status' => 'FINAL', 'value' => '125.00',
        ];

        $increase = $contract->requirements(new CostAllocation([...$base, 'direction' => 'IN']), 'inventory.adjustment');
        $decrease = $contract->requirements(new CostAllocation([...$base, 'direction' => 'OUT']), 'inventory.adjustment');

        $this->assertSame(['INVENTORY_DEFAULT', 'INVENTORY_ADJUSTMENT_GAIN'], $increase['mapping_keys']);
        $this->assertSame(['INVENTORY_DEFAULT', 'INVENTORY_ADJUSTMENT_LOSS'], $decrease['mapping_keys']);
    }
}
