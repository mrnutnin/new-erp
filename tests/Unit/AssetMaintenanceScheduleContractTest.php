<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AssetMaintenanceScheduleContractTest extends TestCase
{
    public function test_daily_alert_only_marks_due_schedules_and_never_creates_maintenance_requests(): void
    {
        $console = file_get_contents(dirname(__DIR__, 2).'/routes/console.php');
        $migration = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_09_01_080000_create_asset_maintenance_schedules_table.php');

        self::assertStringContainsString("Artisan::command('asset:maintenance-alerts'", $console);
        self::assertStringContainsString("Schedule::command('asset:maintenance-alerts')->dailyAt('08:00')", $console);
        self::assertStringContainsString("'last_alerted_at'", $console);
        self::assertStringNotContainsString('AssetMaintenanceRequest::', $console);
        self::assertStringContainsString("'interval_days'", $migration);
        self::assertStringContainsString("'interval_months'", $migration);
    }
}
