<?php

namespace Tests\Unit;

use Tests\TestCase;

class InstallerUatDemoDataSeederContractTest extends TestCase
{
    public function test_uat_demo_seed_is_opt_in_isolated_and_does_not_post_inventory_or_journals(): void
    {
        $seeder = file_get_contents(base_path('database/seeders/UatDemoDataSeeder.php'));
        $controller = file_get_contents(base_path('app/Modules/Installer/Controllers/SetupController.php'));
        $route = file_get_contents(base_path('routes/web.php'));
        $config = file_get_contents(base_path('config/erp.php'));

        foreach (['UAT-WH', 'UAT-RM-001', 'UAT-FG-001', 'UAT-SVC-001', 'UAT-CUST-001', 'UAT-SUP-001', 'UAT-BOX', 'PriceListItem::class', 'BomRevision::query()', 'AssetCategory::class'] as $fixture) {
            self::assertStringContainsString($fixture, $seeder);
        }
        self::assertStringNotContainsString('OpeningBalanceService', $seeder);
        self::assertStringNotContainsString('StockMovement::', $seeder);
        self::assertStringNotContainsString('JournalEntry::', $seeder);
        self::assertStringNotContainsString('JournalPostingService', $seeder);
        self::assertStringContainsString("config('erp.setup.uat_seed_enabled')", $controller);
        self::assertStringContainsString('$this->assertInstallerOpen()', $controller);
        self::assertStringContainsString('installer.seed-uat-demo-data', $route);
        self::assertStringContainsString('ERP_SETUP_UAT_SEED_ENABLED', $config);
    }

    public function test_empty_warehouse_may_start_at_zero_but_an_unposted_opening_batch_stays_blocked(): void
    {
        $runtime = file_get_contents(base_path('app/Modules/Platform/Services/WorkflowRuntimeResolver.php'));

        self::assertStringContainsString('$hasPostedOpening || ! $hasDraftOpening', $runtime);
        self::assertStringContainsString('คลังนี้เริ่มจากยอดศูนย์ได้', $runtime);
        self::assertStringContainsString('ตรวจสอบและ Post หรือยกเลิกร่าง Opening Balance', $runtime);
    }
}
