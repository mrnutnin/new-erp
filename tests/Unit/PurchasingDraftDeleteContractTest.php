<?php

namespace Tests\Unit;

use App\Models\Party;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequisition;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\TestCase;

final class PurchasingDraftDeleteContractTest extends TestCase
{
    public function test_purchasing_draft_routes_use_delete_permissions(): void
    {
        foreach ([
            'purchase-requisitions',
            'purchase-orders',
            'purchase-receipts',
            'purchase-documents',
            'landed-costs',
        ] as $document) {
            $route = app('router')->getRoutes()->getByName("purchasing.{$document}.destroy");

            self::assertNotNull($route, $document);
            self::assertContains('DELETE', $route->methods(), $document);
            self::assertContains("permission:purchasing.{$document}.delete", $route->gatherMiddleware(), $document);
        }
    }

    public function test_purchasing_documents_expose_draft_delete_on_list_and_detail(): void
    {
        foreach (['purchase-requisitions', 'purchase-orders', 'purchase-receipts', 'purchase-documents', 'landed-costs'] as $directory) {
            $index = file_get_contents(base_path("app/Modules/Purchasing/Views/{$directory}/index.blade.php"));
            $show = file_get_contents(base_path("app/Modules/Purchasing/Views/{$directory}/show.blade.php"));

            self::assertStringContainsString('delete_url', $index, $directory);
            self::assertStringContainsString('bx-trash', $index, $directory);
            self::assertStringContainsString('ลบร่าง', $index, $directory);
            self::assertStringContainsString('btn-app-danger', $show, $directory);
            self::assertStringContainsString('bx-trash', $show, $directory);
            self::assertStringContainsString('ลบร่าง', $show, $directory);
        }
    }

    public function test_new_delete_handlers_recheck_scope_and_draft_status_and_audit(): void
    {
        foreach (['PurchaseReceiptController.php', 'LandedCostController.php'] as $controller) {
            $source = file_get_contents(base_path("app/Modules/Purchasing/Controllers/{$controller}"));

            self::assertStringContainsString("status !== 'DRAFT'", $source, $controller);
            self::assertStringContainsString('selectedWarehouse', $source, $controller);
            self::assertStringContainsString('$audit->record(', $source, $controller);
        }
    }

    public function test_purchasing_delete_models_use_soft_deletes(): void
    {
        foreach ([
            Party::class,
            PurchaseRequisition::class,
            PurchaseOrder::class,
            GoodsReceipt::class,
            PurchaseDocument::class,
            LandedCost::class,
        ] as $model) {
            self::assertContains(SoftDeletes::class, class_uses_recursive($model), $model);
        }

        $landedCostController = file_get_contents(base_path('app/Modules/Purchasing/Controllers/LandedCostController.php'));
        self::assertStringNotContainsString('$document->allocations()->delete();', $landedCostController);
    }

    public function test_purchasing_soft_delete_schema_is_installer_required(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_16_100000_add_soft_deletes_to_purchasing_documents.php'));
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));

        foreach (['purchase_requisitions', 'purchase_orders', 'goods_receipts', 'purchase_documents', 'purchasing_landed_costs'] as $table) {
            self::assertStringContainsString("'{$table}'", $migration, $table);
            self::assertStringContainsString("'{$table}' =>", $installer, $table);
        }

        self::assertStringContainsString('$table->softDeletes();', $migration);
        self::assertStringContainsString("'deleted_at'", $installer);
    }
}
