<?php

namespace Tests\Unit;

use Tests\TestCase;

final class FinanceDashboardContractTest extends TestCase
{
    public function test_dashboard_uses_scoped_sections_and_server_side_activity_table(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Finance/Controllers/EntryController.php'));
        $view = file_get_contents(base_path('app/Modules/Finance/Views/dashboard.blade.php'));
        $routes = file_get_contents(base_path('app/Modules/Finance/Routes/web.php'));

        self::assertStringContainsString("['summary', 'cash-trend', 'aging', 'work', 'activities']", $controller);
        self::assertStringContainsString('Cache::remember($cacheKey', $controller);
        self::assertStringContainsString("where('s.status', 'POSTED')", $controller);
        self::assertStringContainsString('finance_advance_deposit_applications', $controller);
        self::assertStringContainsString('finance_allocations', $controller);
        self::assertStringContainsString('petty_cash_balance', $controller);
        self::assertStringContainsString('employee_advance_outstanding', $controller);
        self::assertStringContainsString('internal_transfers_to_post', $controller);
        self::assertStringContainsString('petty_cash_topups_to_post', $controller);
        self::assertStringContainsString('employee_advance_clearings_to_process', $controller);
        self::assertStringContainsString('employee_advances_due_soon', $controller);
        self::assertStringContainsString('postingExceptionCount', $controller);
        self::assertStringContainsString('duplicateReceiptCount', $controller);
        self::assertStringContainsString('DataTables::query($query)', $controller);
        self::assertStringContainsString("/dashboard/data/{section}", $routes);
        self::assertStringContainsString('window.erpDataTableDefaults', $view);
        self::assertStringContainsString('window.erpExcelButton(table)', $view);
        self::assertStringContainsString('$.fn.dataTable.render.text()', $view);
        self::assertStringContainsString('new Chart(', $view);
        self::assertStringContainsString('เงินสดย่อยคงเหลือ', $view);
        self::assertStringContainsString('เงินทดรองคงค้าง', $view);
        self::assertStringContainsString('โอนเงินภายในรอ Post', $view);
        self::assertStringContainsString('สัญญาณควบคุม', $view);
        self::assertStringContainsString('ข้อผิดพลาดการลงบัญชี', $view);
    }
}
