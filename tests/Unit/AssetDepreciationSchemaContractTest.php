<?php

namespace Tests\Unit;

use App\Modules\Asset\Models\AssetDepreciationLine;
use App\Modules\Asset\Models\AssetDepreciationPolicyChange;
use App\Modules\Asset\Models\AssetDepreciationRun;
use PHPUnit\Framework\TestCase;

final class AssetDepreciationSchemaContractTest extends TestCase
{
    public function test_run_schema_keeps_one_active_run_for_each_branch_period_and_book(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_08_31_230000_create_asset_depreciation_run_tables.php');

        self::assertStringContainsString("virtualAs(\"IF(`status` IN ('REVERSED', 'FAILED'), NULL, 1)\")", $migration);
        self::assertStringContainsString('asset_depreciation_runs_active_period_book_unique', $migration);
        self::assertStringContainsString("['branch_id', 'fiscal_period_id', 'book_type', 'active_key']", $migration);
    }

    public function test_models_keep_immutable_calculation_and_policy_snapshots(): void
    {
        self::assertSame('asset_depreciation_runs', (new AssetDepreciationRun)->getTable());
        self::assertSame('asset_depreciation_lines', (new AssetDepreciationLine)->getTable());
        self::assertSame('asset_depreciation_policy_changes', (new AssetDepreciationPolicyChange)->getTable());
        self::assertSame('array', (new AssetDepreciationLine)->getCasts()['calculation_input_snapshot']);
        self::assertSame('array', (new AssetDepreciationPolicyChange)->getCasts()['profile_snapshot']);
    }
}
