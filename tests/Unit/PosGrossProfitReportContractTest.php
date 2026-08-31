<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosGrossProfitReportContractTest extends TestCase
{
    public function test_report_uses_posted_physical_sale_revenue_and_cost_allocation_lineage(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesReportController.php');
        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/reports/gross-profit.blade.php');

        self::assertStringContainsString("->where('sales.status', 'POSTED')", $controller);
        self::assertStringContainsString("->where('allocations.cost_status', 'FINAL')", $controller);
        self::assertStringContainsString('pos_sales_return_inventory_links', $controller);
        self::assertStringContainsString('gross_profit_percent', $controller);
        self::assertStringContainsString("name('sales-reports.gross-profit.data')", $routes);
        self::assertStringContainsString('รายงานกำไรขั้นต้น', $view);
        self::assertStringContainsString('detail_url', $view);
    }
}
