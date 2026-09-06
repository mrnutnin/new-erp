<?php

namespace Tests\Unit;

use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Services\InventoryCostPostingService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryCostPostingServiceTest extends TestCase
{
    public function test_sales_cogs_preview_is_deterministic_and_does_not_create_a_journal(): void
    {
        $first = (new CostAllocation(['allocation_type' => 'ISSUE', 'direction' => 'OUT', 'value' => '25.00']))->forceFill(['id' => 2]);
        $second = (new CostAllocation(['allocation_type' => 'ISSUE', 'direction' => 'OUT', 'value' => '100.00']))->forceFill(['id' => 1]);

        $service = new InventoryCostPostingService;
        $result = $service->buildPreview([$first, $second], 'sales_cogs', [
            'COGS_DEFAULT' => 10,
            'INVENTORY_DEFAULT' => 20,
        ]);

        $this->assertSame([1, 2], $result['allocation_ids']);
        $this->assertSame('DEBIT', $result['lines'][0]['side']);
        $this->assertSame('100.00', $result['lines'][0]['amount']);
        $this->assertSame('CREDIT', $result['lines'][1]['side']);
        $this->assertSame('25.00', $result['lines'][3]['amount']);
    }

    public function test_receipt_and_missing_mapping_are_blocked_before_journal_creation(): void
    {
        $allocation = (new CostAllocation(['allocation_type' => 'RECEIPT', 'direction' => 'IN', 'value' => '10.00']))->forceFill(['id' => 5]);
        $service = new InventoryCostPostingService;

        $this->expectException(ValidationException::class);
        $service->buildPreview($allocation, 'inventory.receipt', ['INVENTORY_DEFAULT' => 20]);
    }

    public function test_recost_preview_uses_directional_gain_or_loss_mapping(): void
    {
        $allocation = (new CostAllocation(['allocation_type' => 'RECOST', 'direction' => 'IN', 'value' => '10.00']))->forceFill(['id' => 7]);
        $result = (new InventoryCostPostingService)->buildPreview($allocation, 'inventory.recost', [
            'INVENTORY_DEFAULT' => 20,
            'INVENTORY_RECOST_GAIN' => 22,
        ]);

        $this->assertSame(['INVENTORY_DEFAULT', 'INVENTORY_RECOST_GAIN'], array_column($result['lines'], 'account_mapping'));
    }

    public function test_transfer_allocation_cannot_be_mapped_to_a_gain_or_loss_journal(): void
    {
        $allocation = (new CostAllocation([
            'allocation_type' => 'TRANSFER', 'direction' => 'OUT', 'value' => '10.00',
        ]))->forceFill(['id' => 6]);

        $this->expectException(ValidationException::class);
        (new InventoryCostPostingService)->buildPreview($allocation, 'inventory.transfer', [
            'INVENTORY_DEFAULT' => 20,
            'INVENTORY_ADJUSTMENT_GAIN' => 21,
            'INVENTORY_ADJUSTMENT_LOSS' => 22,
        ]);
    }

    public function test_duplicate_allocation_ids_are_rejected(): void
    {
        $allocation = (new CostAllocation(['allocation_type' => 'ISSUE', 'direction' => 'OUT', 'value' => '10.00']))->forceFill(['id' => 5]);

        $this->expectException(ValidationException::class);
        (new InventoryCostPostingService)->buildPreview([$allocation, $allocation], 'sales_cogs', [
            'COGS_DEFAULT' => 10,
            'INVENTORY_DEFAULT' => 20,
        ]);
    }
}
