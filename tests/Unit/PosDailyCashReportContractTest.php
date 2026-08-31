<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosDailyCashReportContractTest extends TestCase
{
    public function test_daily_pos_report_uses_posted_hs_iv_tenders_and_cash_refunds(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesReportController.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/reports/daily-sales.blade.php');
        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');

        $this->assertStringContainsString("->where('status', 'POSTED')", $controller);
        $this->assertStringContainsString("->where('sales.document_type', 'HS')", $controller);
        $this->assertStringContainsString("->where('sales.document_type', 'IV')", $controller);
        $this->assertStringContainsString('finance_settlement_allocation_intents', $controller);
        $this->assertStringContainsString('COALESCE(SUM(refund_amount), 0) AS cash_refund', $controller);
        $this->assertStringContainsString('COALESCE(cash_refund, 0)', $controller);
        $this->assertStringContainsString("'CASH' => 'เงินสด'", $controller);
        $this->assertStringContainsString("data:'type_label'", $view);
        $this->assertStringContainsString("name('sales-reports.daily.tenders')", $routes);
        $this->assertStringContainsString('เงินสด/ธนาคารตามช่องทางรับเงิน', $view);
        $this->assertStringContainsString('เปลี่ยนคลังได้จากตัวเลือกด้านบน', $view);
    }
}
