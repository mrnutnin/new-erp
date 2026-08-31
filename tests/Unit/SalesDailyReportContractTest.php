<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SalesDailyReportContractTest extends TestCase
{
    public function test_daily_report_uses_only_posted_pos_documents_and_nets_returns(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesReportController.php');
        $routes = file_get_contents($root.'/app/Modules/POS/Routes/web.php');

        $this->assertStringContainsString("->where('status', 'POSTED')", $controller);
        $this->assertStringContainsString("DB::table('pos_physical_sales')", $controller);
        $this->assertStringContainsString('COALESCE(SUM(hs_sales + iv_sales - return_amount), 0) AS net_sales', $controller);
        $this->assertStringContainsString("name('sales-reports.daily.data')", $routes);
    }
}
