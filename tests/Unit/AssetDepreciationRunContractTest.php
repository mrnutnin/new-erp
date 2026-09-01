<?php

namespace Tests\Unit;

use App\Modules\Asset\Controllers\AssetDepreciationRunController;
use App\Modules\Asset\Services\AssetDepreciationRunService;
use App\Modules\Settings\Support\SettingRegistry;
use PHPUnit\Framework\TestCase;

final class AssetDepreciationRunContractTest extends TestCase
{
    public function test_daily_proration_is_company_policy_and_is_snapshotted_by_runs(): void
    {
        self::assertSame('DAILY', SettingRegistry::DEFINITIONS['asset_depreciation_proration']['default']);
        self::assertStringContainsString("'proration' => \$settings->value('asset_depreciation_proration')", file_get_contents((new \ReflectionClass(AssetDepreciationRunController::class))->getFileName()));
        self::assertStringContainsString("'proration' => \$proration", file_get_contents((new \ReflectionClass(AssetDepreciationRunService::class))->getFileName()));
    }

    public function test_run_service_uses_accounting_contract_and_reverses_book_and_tax_runs(): void
    {
        $service = file_get_contents((new \ReflectionClass(AssetDepreciationRunService::class))->getFileName());

        self::assertStringContainsString('postForBranchWithinTransaction', $service);
        self::assertStringContainsString("'event_code' => 'asset.depreciation'", $service);
        self::assertStringContainsString('if ($run->book_type !== \'BOOK\')', $service);
        self::assertStringContainsString('reverseWithinTransaction', $service);
        self::assertStringContainsString("assertStatus(\$run, 'SUBMITTED')", $service);
    }

    public function test_sidebar_has_the_permission_gated_depreciation_menu(): void
    {
        $sidebar = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Asset/Views/partials/sidebar.blade.php');

        self::assertStringContainsString("hasPermission('asset.depreciation.view')", $sidebar);
        self::assertStringContainsString("route('asset.depreciations.index')", $sidebar);
    }

    public function test_run_selection_requires_a_server_side_exception_reason(): void
    {
        $service = file_get_contents((new \ReflectionClass(AssetDepreciationRunService::class))->getFileName());

        self::assertStringContainsString('AssetDepreciationRunException::query()->create', $service);
        self::assertStringContainsString('exclusion_reasons', $service);
    }

    public function test_approved_policy_is_resolved_prospectively_by_a_run(): void
    {
        $service = file_get_contents((new \ReflectionClass(AssetDepreciationRunService::class))->getFileName());

        self::assertStringContainsString("where('status', 'APPROVED')", $service);
        self::assertStringContainsString("'policy_change_id' => \$policy?->id", $service);
    }
}
