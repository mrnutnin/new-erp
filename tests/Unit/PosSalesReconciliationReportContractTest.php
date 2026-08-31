<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PosSalesReconciliationReportContractTest extends TestCase
{
    public function test_reconciliation_uses_posted_pos_sales_and_ar_sources(): void
    {
        $root = base_path();
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesReportController.php');
        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/reports/sales-reconciliation.blade.php');

        self::assertStringContainsString('public function reconciliationData', $controller);
        self::assertStringContainsString("->where('sales.status', 'POSTED')", $controller);
        self::assertStringContainsString('finance_allocations', $controller);
        self::assertStringContainsString('finance_advance_deposit_applications', $controller);
        self::assertStringContainsString('pos_physical_sale_tenders', $controller);
        self::assertStringContainsString('pos_sales_returns', $controller);
        self::assertStringContainsString('$invoiceOpenItems', $controller);
        self::assertStringContainsString("leftJoinSub(\$invoiceOpenItems, 'open_items'", $controller);
        self::assertStringContainsString('function (QueryBuilder $query)', $controller);
        self::assertStringContainsString("name('sales-reports.reconciliation.data')", $routes);
        self::assertStringContainsString("route('pos.sales-reports.reconciliation.data')", $view);
        self::assertStringContainsString('ลูกหนี้คงเหลือ', $view);
        self::assertStringContainsString('data-journal-preview-url', $view);
        self::assertStringContainsString("CHECK: ['text-bg-danger', 'ต้องตรวจสอบ']", $view);
    }
}
