<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ExecutiveDashboardContractTest extends TestCase
{
    public function test_executive_dashboard_module_contract_is_present(): void
    {
        $root = dirname(__DIR__, 2);

        $this->assertFileExists($root.'/app/Modules/Dashboard/Providers/DashboardServiceProvider.php');
        $this->assertFileExists($root.'/app/Modules/Dashboard/Controllers/ExecutiveDashboardController.php');
        $this->assertFileExists($root.'/app/Modules/Dashboard/Services/ExecutiveDashboardService.php');
        $this->assertFileExists($root.'/app/Modules/Dashboard/Views/dashboard.blade.php');
        $this->assertStringContainsString("Route::get('/dashboard/data'", file_get_contents($root.'/app/Modules/Dashboard/Routes/web.php'));
        $this->assertStringContainsString("dashboard.executive.view", file_get_contents($root.'/database/seeders/RbacSeeder.php'));
    }

    public function test_dashboard_ui_preserves_executive_questions_and_ajax_refresh(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Dashboard/Views/dashboard.blade.php');

        foreach (['executive-date-from', 'executive-company', 'executive-branch', 'executive-business-unit', 'executive-trend-chart', 'executive-attention', 'executive-decisions'] as $marker) {
            $this->assertStringContainsString($marker, $view);
        }

        $this->assertStringContainsString("$.getJSON(root.data('url'), filters)", $view);
        $this->assertStringContainsString("$('#executive-apply').on('click', load)", $view);
        $this->assertStringContainsString('change_percent', $view);
        $this->assertStringContainsString('ApexCharts', $view);
        $this->assertStringContainsString('executive-chart-fallback', $view);
        $this->assertStringContainsString('@media (max-width: 767.98px)', $view);
        $service = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Dashboard/Services/ExecutiveDashboardService.php');
        $this->assertStringContainsString("'wms.stock.index'", $service);
        $this->assertStringContainsString("'finance.settlements.index'", $service);
        $this->assertStringContainsString('drillDownUrl', $service);
        $this->assertStringContainsString('history.replaceState', $view);
        $this->assertStringContainsString('@selected((string) ($filters[\'branch_id\'] ?? \'all\')', $view);
    }
}
