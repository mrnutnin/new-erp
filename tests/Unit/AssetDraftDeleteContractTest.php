<?php

namespace Tests\Unit;

use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCapitalization;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetCount;
use App\Modules\Asset\Models\AssetDepreciationPolicyChange;
use App\Modules\Asset\Models\AssetDepreciationRun;
use App\Modules\Asset\Models\AssetDisposal;
use App\Modules\Asset\Models\AssetImpairment;
use App\Modules\Asset\Models\AssetLocation;
use App\Modules\Asset\Models\AssetMaintenanceRequest;
use App\Modules\Asset\Models\AssetMaintenanceSchedule;
use App\Modules\Asset\Models\AssetTransfer;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\TestCase;

final class AssetDraftDeleteContractTest extends TestCase
{
    public function test_asset_draft_documents_expose_consistent_delete_actions(): void
    {
        foreach (['assets', 'capitalizations', 'transfers', 'counts', 'depreciations', 'depreciation-policies', 'impairments', 'disposals'] as $directory) {
            $index = file_get_contents(base_path("app/Modules/Asset/Views/{$directory}/index.blade.php"));
            $show = file_get_contents(base_path("app/Modules/Asset/Views/{$directory}/show.blade.php"));

            self::assertStringContainsString('delete_url', $index, $directory);
            self::assertStringContainsString('bx-trash', $index, $directory);
            self::assertStringContainsString('ลบร่าง', $index, $directory);
            self::assertStringContainsString('btn-app-danger', $show, $directory);
            self::assertStringContainsString('bx-trash', $show, $directory);
            self::assertStringContainsString('ลบร่าง', $show, $directory);
        }
    }

    public function test_asset_draft_delete_routes_use_existing_draft_owner_permissions(): void
    {
        $routes = [
            'assets' => 'asset.register.update',
            'capitalizations' => 'asset.capitalizations.create',
            'additions' => 'asset.capitalizations.create',
            'transfers' => 'asset.transfers.create',
            'counts' => 'asset.counts.create',
            'depreciations' => 'asset.depreciation.calculate',
            'depreciation-policies' => 'asset.depreciation.calculate',
            'impairments' => 'asset.impairments.create',
            'disposals' => 'asset.disposals.create',
        ];

        foreach ($routes as $name => $permission) {
            $route = app('router')->getRoutes()->getByName("asset.{$name}.destroy");

            self::assertNotNull($route, $name);
            self::assertContains('DELETE', $route->methods(), $name);
            self::assertContains("permission:{$permission}", $route->gatherMiddleware(), $name);
        }
    }

    public function test_asset_delete_handlers_recheck_draft_status_and_audit(): void
    {
        foreach ([
            'AssetController.php',
            'AssetCapitalizationController.php',
            'AssetTransferController.php',
            'AssetCountController.php',
            'AssetDepreciationRunController.php',
            'AssetDepreciationPolicyChangeController.php',
            'AssetImpairmentController.php',
            'AssetDisposalController.php',
        ] as $controller) {
            $source = file_get_contents(base_path("app/Modules/Asset/Controllers/{$controller}"));

            self::assertStringContainsString("status !== 'DRAFT'", $source, $controller);
            self::assertStringContainsString('$audit->record(', $source, $controller);
        }
    }

    public function test_all_asset_delete_roots_use_soft_deletes(): void
    {
        foreach ([
            Asset::class,
            AssetCategory::class,
            AssetLocation::class,
            AssetCapitalization::class,
            AssetTransfer::class,
            AssetCount::class,
            AssetDepreciationRun::class,
            AssetDepreciationPolicyChange::class,
            AssetImpairment::class,
            AssetDisposal::class,
            AssetMaintenanceRequest::class,
            AssetMaintenanceSchedule::class,
        ] as $model) {
            self::assertContains(SoftDeletes::class, class_uses_recursive($model), $model);
        }
    }

    public function test_asset_soft_delete_schema_is_covered_by_migration_and_installer(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_16_140000_add_soft_deletes_to_asset_depreciation_documents.php'));
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));

        foreach (['asset_depreciation_runs', 'asset_depreciation_policy_changes'] as $table) {
            self::assertStringContainsString("'{$table}'", $migration);
        }

        foreach ([
            'assets',
            'asset_categories',
            'asset_locations',
            'asset_capitalizations',
            'asset_transfers',
            'asset_counts',
            'asset_depreciation_runs',
            'asset_depreciation_policy_changes',
            'asset_impairments',
            'asset_disposals',
            'asset_maintenance_requests',
            'asset_maintenance_schedules',
        ] as $table) {
            self::assertMatchesRegularExpression("/'{$table}'\\s*=>\\s*\\[[^\\]]*'deleted_at'/", $installer);
        }
    }

    public function test_soft_deleted_depreciation_run_keeps_audit_details(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Asset/Controllers/AssetDepreciationRunController.php'));

        self::assertStringNotContainsString('$run->lines()->delete();', $controller);
        self::assertStringNotContainsString('$run->exceptions()->delete();', $controller);
    }
}
