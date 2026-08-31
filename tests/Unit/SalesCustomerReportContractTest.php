<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SalesCustomerReportContractTest extends TestCase
{
    public function test_customer_report_uses_posted_pos_sales_returns_and_current_iv_ar_balance(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesReportController.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/reports/customer-sales.blade.php');

        $this->assertStringContainsString("DB::table('pos_physical_sales as sales')", $controller);
        $this->assertStringContainsString("DB::table('pos_sales_returns as returns')", $controller);
        $this->assertStringContainsString("where('invoice_sales.document_type', 'IV')", $controller);
        $this->assertStringContainsString("where('open_items.warehouse_id', \$request->attributes->get('selectedWarehouse')->id)", $controller);
        $this->assertStringContainsString('finance_advance_deposit_applications', $controller);
        $this->assertStringContainsString('SUM(hs_amount + iv_amount - return_amount) AS net_sales', $controller);
        $this->assertStringContainsString('คงเหลือ ณ วันนี้', $view);
    }
}
