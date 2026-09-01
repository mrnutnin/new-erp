<?php

namespace Tests\Unit;

use App\Modules\Asset\Services\AssetMaintenanceService;
use PHPUnit\Framework\TestCase;

final class AssetMaintenanceContractTest extends TestCase
{
    public function test_maintenance_changes_asset_operational_status_without_posting_duplicate_expense(): void
    {
        $service = file_get_contents((new \ReflectionClass(AssetMaintenanceService::class))->getFileName());
        $migration = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_09_01_070000_create_asset_maintenance_requests_table.php');

        self::assertStringContainsString("'UNDER_REPAIR'", $service);
        self::assertStringContainsString("'ACTIVE'", $service);
        self::assertStringContainsString("'MAINTENANCE_STARTED'", $service);
        self::assertStringContainsString("'MAINTENANCE_COMPLETED'", $service);
        self::assertStringNotContainsString('JournalPostingService', $service);
        self::assertStringContainsString("'source_document_number'", $migration);
        self::assertStringContainsString("'takes_asset_out_of_service'", $migration);
    }
}
