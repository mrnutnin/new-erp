<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SalesItemReportContractTest extends TestCase
{
    public function test_item_report_nets_posted_hs_iv_and_return_lines(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesReportController.php');
        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/reports/item-sales.blade.php');

        $this->assertStringContainsString("->where('sales.status', 'POSTED')", $controller);
        $this->assertStringContainsString("DB::table('pos_sales_return_lines as return_lines')", $controller);
        $this->assertStringContainsString('SUM(hs_quantity + iv_quantity - return_quantity) AS net_quantity', $controller);
        $this->assertStringContainsString("name('sales-reports.item.data')", $routes);
        $this->assertStringContainsString('ยอดขายสุทธิ', $view);
    }
}
