<?php

namespace Tests\Unit;

use App\Modules\Asset\Controllers\EntryController;
use PHPUnit\Framework\TestCase;

final class AssetMaintenanceDashboardContractTest extends TestCase
{
    public function test_dashboard_alerts_are_branch_scoped_and_read_only(): void
    {
        $controller = file_get_contents((new \ReflectionClass(EntryController::class))->getFileName());

        self::assertStringContainsString('maintenanceAlerts', $controller);
        self::assertStringContainsString("where('branch_id', \$branchId)", $controller);
        self::assertStringContainsString("where('priority', 'CRITICAL')", $controller);
        self::assertStringNotContainsString('->update(', $controller);
    }
}
