<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CrmReportsContractTest extends TestCase
{
    #[Test]
    public function report_center_exposes_four_scoped_reports(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Crm/Routes/web.php'));
        $controller = file_get_contents(base_path('app/Modules/Crm/Controllers/ReportController.php'));
        $view = file_get_contents(base_path('app/Modules/Crm/Views/reports/index.blade.php'));
        $design = file_get_contents(base_path('CRM_REPORTS_DESIGN.md'));
        self::assertStringContainsString("name('reports.index')", $routes);
        self::assertStringContainsString("name('reports.data')", $routes);
        self::assertStringContainsString("name('reports.export')", $routes);
        self::assertStringContainsString('SpreadsheetService', $controller);
        self::assertStringContainsString("middleware('permission:crm.forecast.view')", $routes);
        foreach (['pipeline', 'sales', 'activities', 'losses'] as $report) {
            self::assertStringContainsString("'{$report}'", $controller);
            self::assertStringContainsString("data-report-tab=\"{$report}\"", $view);
        }
        self::assertStringContainsString('whereBetween', $controller);
        self::assertStringContainsString('branch_id', $controller);
        self::assertStringContainsString('crm.reports.index', $view);
        self::assertStringContainsString('กิจกรรมทีมขาย', $design);
    }
}
