<?php

namespace Tests\Unit;

use Tests\TestCase;

final class WmsDashboardContractTest extends TestCase
{
    public function test_dashboard_exposes_scoped_cached_sections_and_server_side_tables(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Wms/Controllers/EntryController.php'));
        $view = file_get_contents(base_path('app/Modules/Wms/Views/dashboard.blade.php'));
        $routes = file_get_contents(base_path('app/Modules/Wms/Routes/web.php'));

        self::assertStringContainsString("['summary', 'work', 'trend', 'low-stock', 'movements']", $controller);
        self::assertStringContainsString('Cache::remember($key', $controller);
        self::assertStringContainsString('DataTables::eloquent($query)', $controller);
        self::assertStringContainsString("/dashboard/data/{section}", $routes);
        self::assertStringContainsString("data-url=\"{{ route('wms.dashboard.data'", $view);
        self::assertStringContainsString('window.erpDataTableDefaults', $view);
        self::assertStringContainsString('pageLength:5', $view);
        self::assertStringContainsString('new Chart(', $view);
        self::assertStringContainsString('สินค้าต่ำกว่าจุด Min', $view);
        self::assertStringContainsString('ความเคลื่อนไหวล่าสุด', $view);
    }
}
