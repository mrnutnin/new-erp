<?php

namespace Tests\Unit;

use Tests\TestCase;

final class InventoryReconciliationScopeTest extends TestCase
{
    public function test_legacy_review_count_is_warehouse_scoped_and_open_only(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/InventoryReconciliationService.php'));
        $this->assertStringContainsString("where('allocations.warehouse_id', \$warehouseId)", $source);
        $this->assertStringContainsString("where('reviews.status', 'OPEN')", $source);
        $this->assertStringContainsString('wms_cost_allocation_reviews', $source);
        $this->assertStringNotContainsString('withoutGlobalScopes', $source);
    }
}
