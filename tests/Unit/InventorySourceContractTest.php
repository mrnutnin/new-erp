<?php

namespace Tests\Unit;

use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\InventorySourceContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventorySourceContractTest extends TestCase
{
    public function test_sales_cogs_requires_pos_issue_source_and_matching_allocation(): void
    {
        $movement = $this->movement('ISSUE', 'OUT', 'POS');
        $allocation = $this->allocation($movement);

        InventorySourceContract::assertCompatible($movement, $allocation, 'sales_cogs');

        $this->expectException(ValidationException::class);
        InventorySourceContract::assertCompatible($movement, $allocation, 'inventory.receipt');
    }

    public function test_receipt_accepts_purchasing_source_but_adjustment_requires_inventory_source(): void
    {
        $receipt = $this->movement('RECEIPT', 'IN', 'PURCHASING');
        InventorySourceContract::assertCompatible($receipt, $this->allocation($receipt), 'inventory.receipt');

        $adjustment = $this->movement('COUNT', 'IN', 'INVENTORY');
        InventorySourceContract::assertCompatible($adjustment, $this->allocation($adjustment), 'inventory.adjustment');
        $this->assertTrue(true);
    }

    public function test_it_rejects_missing_source_identity(): void
    {
        $movement = $this->movement('ISSUE', 'OUT', 'POS');
        $missingSource = $movement->newInstance($movement->getAttributes());
        $missingSource->source_reference = null;
        $this->expectException(ValidationException::class);
        InventorySourceContract::assertCompatible($missingSource, $this->allocation($missingSource), 'sales_cogs');
    }

    public function test_it_rejects_cross_movement_allocation(): void
    {
        $movement = $this->movement('ISSUE', 'OUT', 'POS');
        $otherMovement = $this->movement('ISSUE', 'OUT', 'POS');
        $otherMovement->forceFill(['id' => 10]);
        $this->expectException(ValidationException::class);
        InventorySourceContract::assertCompatible($movement, $this->allocation($otherMovement), 'sales_cogs');
    }

    private function movement(string $type, string $direction, string $sourceType): StockMovement
    {
        return (new StockMovement([
            'warehouse_id' => 7, 'item_id' => 4, 'uom_id' => 2,
            'movement_type' => $type, 'direction' => $direction,
            'business_date' => '2026-08-21', 'source_type' => $sourceType,
            'source_id' => 'source-9', 'source_reference' => 'DOC-009',
        ]))->forceFill(['id' => 9]);
    }

    private function allocation(StockMovement $movement): CostAllocation
    {
        return new CostAllocation([
            'stock_movement_id' => $movement->id, 'warehouse_id' => $movement->warehouse_id,
            'item_id' => $movement->item_id, 'uom_id' => $movement->uom_id,
            'business_date' => $movement->business_date,
        ]);
    }
}
