<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosSalesDashboardContractTest extends TestCase
{
    public function test_dashboard_is_branch_scoped_and_uses_local_chartjs(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/EntryController.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/dashboard.blade.php');
        $layout = file_get_contents($root.'/resources/views/layouts/app.blade.php');
        $manifest = file_get_contents($root.'/public/vendor/manifest.json');

        self::assertStringContainsString("where('branch_id', \$branchId)", $controller);
        self::assertStringContainsString("DB::table('pos_sales_returns as returns')", $controller);
        self::assertStringContainsString("where('sales.status', 'POSTED')", $controller);
        self::assertStringContainsString("where('status', 'POSTED')", $controller);
        self::assertStringContainsString('targetNetSales', $controller);
        self::assertStringContainsString('Cache::remember', $controller);
        self::assertStringContainsString("'summary', 'trend', 'mix', 'work', 'recent', 'top-items', 'document-counts', 'receivable-alert'", $controller);
        self::assertStringContainsString('orderByDesc(\'net_sales\')', $controller);
        self::assertStringContainsString("where('status', 'COMPLETED')", $controller);
        self::assertStringContainsString("where('status', 'APPROVED')", $controller);
        self::assertStringContainsString("where('status', 'CONFIRMED')", $controller);
        self::assertStringContainsString('sales-trend-chart', $view);
        self::assertStringContainsString('sales-mix-chart', $view);
        self::assertStringContainsString('target-chart', $view);
        self::assertStringContainsString('credit_note_month', $view);
        self::assertStringContainsString('top-items-body', $view);
        self::assertStringContainsString('document-counts-body', $view);
        self::assertStringContainsString("request('receivable-alert'", $view);
        self::assertStringContainsString("asset('vendor/chartjs/chart.umd.min.js')", $layout);
        self::assertStringContainsString('"chartjs"', $manifest);
    }
}
