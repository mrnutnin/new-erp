<?php

namespace Tests\Unit;

use Tests\TestCase;

final class InventoryAdjustmentPostingServiceTest extends TestCase
{
    public function test_internal_adjustment_service_is_feature_gated_and_atomic(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/InventoryAdjustmentPostingService.php'));

        $this->assertStringContainsString("config('erp.inventory.adjustment_posting_enabled', false)", $source);
        $this->assertStringContainsString('DB::transaction', $source);
        $this->assertStringContainsString('InventoryAdjustmentPostingContract::preview', $source);
        $this->assertStringContainsString("'event_code' => 'inventory_adjustment'", $source);
        $this->assertStringContainsString('wms.inventory_adjustment.posted', $source);
        $this->assertStringNotContainsString('Route::post', $source);
    }
}
