<?php

namespace Tests\Unit;

use Tests\TestCase;

final class InventoryAdjustmentDetailUiTest extends TestCase
{
    public function test_adjustment_detail_is_warehouse_scoped_and_exposes_trace_sections(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Wms/Routes/web.php'));
        $controller = file_get_contents(base_path('app/Modules/Wms/Controllers/InventoryAdjustmentController.php'));
        $view = file_get_contents(base_path('app/Modules/Wms/Views/inventory-adjustments/show.blade.php'));
        $audit = file_get_contents(base_path('app/Models/AuditLog.php'));

        $this->assertStringContainsString("name('inventory-adjustments.show')", $routes);
        $this->assertStringContainsString("middleware(['auth', 'program:wms', 'warehouse'])", $routes);
        $this->assertStringContainsString("'allocation.journalEntry.lines.account", $controller);
        $this->assertStringContainsString('AuditLog::query()', $controller);
        $this->assertStringContainsString('Stock Movement', $view);
        $this->assertStringContainsString('Cost Allocation', $view);
        $this->assertStringContainsString('Journal', $view);
        $this->assertStringContainsString('ประวัติรายการ', $view);
        $this->assertStringContainsString("wms.inventory_adjustment.posted' => 'ลงบัญชีรายการปรับปรุงสินค้า'", $audit);
    }
}
