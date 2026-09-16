<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AssetListFilterUiContractTest extends TestCase
{
    public function test_import_batches_support_status_and_cutover_date_filters(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Asset/';
        $view = file_get_contents($root.'Views/imports/index.blade.php');
        $controller = file_get_contents($root.'Controllers/AssetImportController.php');

        self::assertStringContainsString('import-filter-status', $view);
        self::assertStringContainsString('import-filter-date-from', $view);
        self::assertStringContainsString('import-filter-date-to', $view);
        self::assertStringContainsString('cutover_date_from', $view);
        self::assertStringContainsString('cutover_date_to', $view);
        self::assertStringContainsString("whereDate('cutover_date', '>=', \$date)", $controller);
        self::assertStringContainsString("whereDate('cutover_date', '<=', \$date)", $controller);
    }

    public function test_maintenance_schedules_support_due_date_range_filters(): void
    {
        $root = dirname(__DIR__, 2).'/app/Modules/Asset/';
        $view = file_get_contents($root.'Views/maintenance/schedules/index.blade.php');
        $controller = file_get_contents($root.'Controllers/AssetMaintenanceScheduleController.php');

        self::assertStringContainsString('schedule-date-from', $view);
        self::assertStringContainsString('schedule-date-to', $view);
        self::assertStringContainsString('date_from', $view);
        self::assertStringContainsString('date_to', $view);
        self::assertStringContainsString("whereDate('next_due_date', '>=', \$date)", $controller);
        self::assertStringContainsString("whereDate('next_due_date', '<=', \$date)", $controller);
    }
}
