<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosSalesTargetPerformanceReportContractTest extends TestCase
{
    public function test_report_uses_posted_sales_final_cogs_and_target_period_coverage(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesReportController.php');
        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/reports/sales-target-performance.blade.php');

        self::assertStringContainsString("where('allocations.cost_status', 'FINAL')", $controller);
        self::assertStringContainsString("where('sales.status', 'POSTED')", $controller);
        self::assertStringContainsString("where('allocations.cost_status', 'FINAL')", $controller);
        self::assertStringContainsString("whereIn('sales.warehouse_id', \$warehouseIds)", $controller);
        self::assertStringContainsString("where('period_start', \$from)", $controller);
        self::assertStringContainsString("where('period_end', \$to)", $controller);
        self::assertStringContainsString("leftJoin('sales_orders as orders'", $controller);
        self::assertStringContainsString('intake_direct.prepared_by', $controller);
        self::assertStringContainsString("name('sales-reports.sales-target-performance.data')", $routes);
        self::assertStringContainsString('branch-sales-target-table', $view);
        self::assertStringContainsString('employee-sales-target-table', $view);
        self::assertStringContainsString('branch-target-chart', $view);
        self::assertStringContainsString('employee-target-chart', $view);
        self::assertStringContainsString('$.extend(data, params(scope))', $view);
    }
}
