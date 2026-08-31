<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\InventoryAdjustmentPostingContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class InventoryAdjustmentPostingContractTest extends TestCase
{
    private function input(array $overrides = []): array
    {
        return array_merge([
            'direction' => 'GAIN', 'reason' => 'ตรวจนับสินค้าปลายเดือน', 'warehouse_id' => 2, 'item_id' => 4,
            'uom_id' => 10, 'business_date' => '2026-08-22', 'quantity' => '2', 'value' => '25.00',
            'source_type' => 'WMS_ADJUSTMENT', 'source_id' => 'ADJ-001', 'source_reference' => 'นับสินค้ารอบเดือน',
            'idempotency_key' => 'adjustment:ADJ-001', 'approved' => true, 'period_open' => true, 'reconciled' => true,
        ], $overrides);
    }

    public function test_gain_and_loss_resolve_directional_mapping_without_writes(): void
    {
        $gain = InventoryAdjustmentPostingContract::preview($this->input());
        $loss = InventoryAdjustmentPostingContract::preview($this->input(['direction' => 'LOSS']));

        $this->assertSame('IN', $gain['movement_direction']);
        $this->assertSame('INVENTORY_ADJUSTMENT_GAIN', $gain['mapping_key']);
        $this->assertSame('OUT', $loss['movement_direction']);
        $this->assertSame('INVENTORY_ADJUSTMENT_LOSS', $loss['mapping_key']);
        $this->assertFalse($gain['creates_journal']);
    }

    public function test_adjustment_requires_reason_source_and_all_gates(): void
    {
        foreach ([['reason' => 'สั้น'], ['source_id' => ''], ['approved' => false], ['period_open' => false], ['reconciled' => false]] as $override) {
            try {
                InventoryAdjustmentPostingContract::preview($this->input($override));
                $this->fail('Expected validation exception');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_same_idempotency_payload_is_retryable_but_changed_payload_is_blocked(): void
    {
        $first = InventoryAdjustmentPostingContract::preview($this->input());
        $retry = InventoryAdjustmentPostingContract::preview($this->input(['existing_posting_hash' => $first['posting_hash']]));
        $this->assertSame($first['posting_hash'], $retry['posting_hash']);

        $this->expectException(ValidationException::class);
        InventoryAdjustmentPostingContract::preview($this->input(['value' => '26.00', 'existing_posting_hash' => $first['posting_hash']]));
    }
}
