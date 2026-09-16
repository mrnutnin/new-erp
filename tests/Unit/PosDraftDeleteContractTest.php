<?php

namespace Tests\Unit;

use App\Models\CustomerGroup;
use App\Models\Party;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Pos\Models\BillingNote;
use App\Modules\Pos\Models\BranchSalesTarget;
use App\Modules\Pos\Models\EmployeeSalesTarget;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\PriceList;
use App\Modules\Pos\Models\Promotion;
use App\Modules\Pos\Models\SalesCommissionPlan;
use App\Modules\Pos\Models\SalesDocument;
use App\Modules\Pos\Models\SalesIntake;
use App\Modules\Pos\Models\SalesOrder;
use App\Modules\Pos\Models\SalesQuotation;
use App\Modules\Pos\Models\SalesReturn;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tests\TestCase;

final class PosDraftDeleteContractTest extends TestCase
{
    public function test_pos_draft_documents_expose_consistent_delete_actions(): void
    {
        $documents = [
            'sales-intakes' => 'pos.sales-intakes.delete',
            'sales-quotations' => 'pos.sales-quotations.delete',
            'sales-orders' => 'pos.sales-orders.delete',
            'physical-sales' => 'pos.physical-sales.delete',
            'receipts' => 'pos.receipts.delete',
            'sales-returns' => 'pos.sales-returns.delete',
            'sales-documents' => 'pos.sales-documents.delete',
            'billing-notes' => 'pos.billing-notes.delete',
        ];

        foreach ($documents as $directory => $permission) {
            $show = file_get_contents(base_path("app/Modules/Pos/Views/{$directory}/show.blade.php"));
            $index = file_get_contents(base_path("app/Modules/Pos/Views/{$directory}/index.blade.php"));

            self::assertStringContainsString($permission, $show, $directory);
            self::assertStringContainsString('delete_url', $index, $directory);
            self::assertStringContainsString('bx-trash', $index, $directory);
            self::assertStringContainsString('ลบร่าง', $index, $directory);
        }
    }

    public function test_each_pos_draft_delete_route_has_its_own_permission(): void
    {
        foreach (['sales-intakes', 'sales-quotations', 'sales-orders', 'physical-sales', 'receipts', 'sales-returns', 'sales-documents', 'billing-notes'] as $name) {
            $route = app('router')->getRoutes()->getByName("pos.{$name}.destroy");

            self::assertNotNull($route, $name);
            self::assertContains('DELETE', $route->methods(), $name);
            self::assertContains("permission:pos.{$name}.delete", $route->gatherMiddleware(), $name);
        }
    }

    public function test_shared_detail_button_is_draft_only_and_destructive(): void
    {
        $button = file_get_contents(base_path('app/Modules/Pos/Views/partials/draft-delete-button.blade.php'));

        self::assertStringContainsString("status === 'DRAFT'", $button);
        self::assertStringContainsString('btn-app-danger', $button);
        self::assertStringContainsString('bx bx-trash', $button);
        self::assertStringContainsString('</i>ลบร่าง', $button);
    }

    public function test_all_pos_models_exposed_to_delete_use_soft_deletes(): void
    {
        foreach ([
            BillingNote::class,
            BranchSalesTarget::class,
            CustomerGroup::class,
            EmployeeSalesTarget::class,
            Party::class,
            PhysicalSale::class,
            PriceList::class,
            Promotion::class,
            SalesCommissionPlan::class,
            SalesDocument::class,
            SalesIntake::class,
            SalesOrder::class,
            SalesQuotation::class,
            SalesReturn::class,
            Settlement::class,
        ] as $model) {
            self::assertContains(SoftDeletes::class, class_uses_recursive($model), $model);
        }
    }

    public function test_pos_soft_delete_schema_is_covered_by_migration_and_installer(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_16_120000_add_soft_deletes_to_pos_documents.php'));
        $installer = file_get_contents(base_path('app/Modules/Installer/Services/DatabasePreparationService.php'));

        foreach ([
            'sales_intakes',
            'sales_quotations',
            'sales_orders',
            'pos_physical_sales',
            'sales_documents',
            'pos_sales_returns',
        ] as $table) {
            self::assertStringContainsString("'{$table}'", $migration);
            self::assertMatchesRegularExpression("/'{$table}'\\s*=>\\s*\\[[^\\]]*'deleted_at'/", $installer);
        }
    }

    public function test_recreatable_quotation_and_order_sources_are_not_database_unique(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_16_120000_add_soft_deletes_to_pos_documents.php'));

        self::assertStringContainsString("dropUnique('sales_quotations_rfq_unique')", $migration);
        self::assertStringContainsString("dropUnique('sales_orders_quotation_unique')", $migration);
        self::assertStringContainsString("dropUnique('sales_orders_rfq_unique')", $migration);
    }
}
