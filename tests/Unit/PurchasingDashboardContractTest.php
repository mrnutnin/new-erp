<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Controllers\EntryController;
use Tests\TestCase;

final class PurchasingDashboardContractTest extends TestCase
{
    public function test_dashboard_uses_scoped_lazy_sections_and_chart_js(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/EntryController.php'));
        $view = file_get_contents(base_path('app/Modules/Purchasing/Views/dashboard.blade.php'));
        $routes = file_get_contents(base_path('app/Modules/Purchasing/Routes/web.php'));

        self::assertStringContainsString("Cache::remember(\"purchasing:dashboard:", $controller);
        self::assertStringContainsString("where('warehouse_id', \$warehouseId)", $controller);
        self::assertStringContainsString("/dashboard/data/{section}", $routes);
        self::assertStringContainsString("data-url=\"{{ route('purchasing.dashboard.data'", $view);
        self::assertStringContainsString("new Chart(", $view);
        self::assertStringContainsString("load('summary'", $view);
        self::assertStringContainsString("load('work'", $view);
        self::assertStringContainsString("load('trend'", $view);
        self::assertStringContainsString("load('recent'", $view);
    }
}
