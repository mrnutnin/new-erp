<?php

namespace Tests\Unit;

use App\Modules\Wms\Models\InventoryAdjustment;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\ItemCategory;
use App\Modules\Wms\Models\OpeningBalanceBatch;
use App\Modules\Wms\Models\StockCountDocument;
use App\Modules\Wms\Models\Transfer;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\TestCase;

final class WmsDraftDeleteContractTest extends TestCase
{
    public function test_wms_draft_document_routes_use_delete_permissions(): void
    {
        $routes = [
            'wms.opening-balances.destroy' => 'wms.opening-balances.delete',
            'wms.issues.destroy' => 'wms.issues.delete',
            'wms.issue-returns.destroy' => 'wms.issue-returns.delete',
            'wms.stock-counts.destroy' => 'wms.stock-counts.delete',
            'wms.inventory-adjustments.documents.delete' => 'wms.inventory-adjustments.delete',
            'wms.transfers.destroy' => 'wms.transfers.delete',
        ];

        foreach ($routes as $name => $permission) {
            $route = app('router')->getRoutes()->getByName($name);

            self::assertNotNull($route, $name);
            self::assertContains('DELETE', $route->methods(), $name);
            self::assertContains("permission:{$permission}", $route->gatherMiddleware(), $name);
        }
    }

    public function test_opening_balance_and_stock_count_expose_draft_delete_on_list_and_detail(): void
    {
        foreach (['opening-balances', 'stock-counts'] as $directory) {
            $index = file_get_contents(base_path("app/Modules/Wms/Views/{$directory}/index.blade.php"));
            $show = file_get_contents(base_path("app/Modules/Wms/Views/{$directory}/show.blade.php"));

            self::assertStringContainsString('delete_url', $index, $directory);
            self::assertStringContainsString('bx-trash', $index, $directory);
            self::assertStringContainsString('ลบร่าง', $index, $directory);
            self::assertStringContainsString('btn-app-danger', $show, $directory);
            self::assertStringContainsString('bx-trash', $show, $directory);
            self::assertStringContainsString('ลบร่าง', $show, $directory);
        }
    }

    public function test_opening_balance_delete_rechecks_scope_and_draft_status_and_audits(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Controllers/OpeningBalanceController.php'));

        self::assertStringContainsString("status === 'DRAFT'", $source);
        self::assertStringContainsString('accessibleWarehouses($request)', $source);
        self::assertStringContainsString("audit->record('wms.opening_balance.deleted'", $source);
    }

    public function test_all_wms_models_exposed_to_draft_delete_use_soft_deletes(): void
    {
        foreach ([
            InventoryAdjustment::class,
            InventoryAdjustmentDocument::class,
            Item::class,
            ItemCategory::class,
            IssueDocument::class,
            IssueReturn::class,
            OpeningBalanceBatch::class,
            StockCountDocument::class,
            Transfer::class,
            Uom::class,
        ] as $model) {
            self::assertContains(SoftDeletes::class, class_uses_recursive($model), $model);
        }
    }

    public function test_wms_soft_delete_schema_is_covered_by_migration_and_installer(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_16_110000_add_soft_deletes_to_wms_documents.php'));
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));

        foreach ([
            'wms_inventory_adjustments',
            'wms_inventory_adjustment_documents',
            'wms_opening_balance_batches',
            'wms_stock_count_documents',
        ] as $table) {
            self::assertStringContainsString("'{$table}'", $migration);
            self::assertMatchesRegularExpression("/'{$table}'\\s*=>\\s*\\[[^\\]]*'deleted_at'/", $installer);
        }
    }

    public function test_soft_deleted_aggregate_roots_keep_their_detail_rows(): void
    {
        $openingBalances = file_get_contents(base_path('app/Modules/Wms/Controllers/OpeningBalanceController.php'));
        $transfer = file_get_contents(base_path('app/Modules/Wms/Models/Transfer.php'));

        self::assertStringNotContainsString('$locked->lines()->delete();', $openingBalances);
        self::assertStringNotContainsString('$transfer->lines()->delete();', $transfer);
    }
}
