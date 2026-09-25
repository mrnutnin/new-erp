<?php

namespace Tests\Unit;

use Tests\TestCase;

final class InventoryPreflightUiContractTest extends TestCase
{
    public function test_preflight_summary_exposes_closed_posting_state_and_ui_guidance(): void
    {
        $service = file_get_contents(base_path('app/Modules/Wms/Services/InventoryPostingPreflightService.php'));
        $view = file_get_contents(base_path('app/Modules/Wms/Views/stock-valuation/index.blade.php'));
        $workflowRuntime = file_get_contents(base_path('app/Modules/Platform/Services/WorkflowRuntimeResolver.php'));
        $workflowCard = file_get_contents(base_path('app/Modules/Platform/Views/workflow/_workflow-card.blade.php'));

        $this->assertStringContainsString("'posting_enabled' => (bool) config('erp.inventory.purchase_posting_enabled', false)", $service);
        $this->assertStringContainsString('global_unresolved_legacy_review', $service);
        $this->assertStringContainsString('reconciliation_blockers', $service);
        $this->assertStringContainsString("'missing_inventory_items' => \$missingInventoryItems", $service);
        $this->assertStringContainsString("'unlinked_allocation_details' => \$unlinkedAllocationDetails", $service);
        $this->assertStringContainsString("'missing_inventory_items' => \$summary['missing_inventory_items'] ?? []", $workflowRuntime);
        $this->assertStringContainsString("'reconciliation_metrics' => \$summary['reconciliation'] ?? []", $workflowRuntime);
        $this->assertStringContainsString('InventoryGlScope::DEFERRED_SOURCES', $workflowRuntime);
        $this->assertStringContainsString('ขั้นตอนถัดไป:', $workflowCard);
        $this->assertStringContainsString("route('wms.items.edit', \$item['id'])", $workflowCard);
        $this->assertStringContainsString('ผลต่าง Allocation ↔ GL', $workflowCard);
        $this->assertStringContainsString('ตัวอย่าง Allocation ที่ยังไม่มี Journal', $workflowCard);
        $this->assertStringContainsString('Inventory→GL posting scope', $workflowRuntime);
        $this->assertStringContainsString('โหมด Preview เท่านั้น', $view);
        $this->assertStringContainsString('Global มี Legacy review', $view);
        $this->assertStringContainsString('preflight-legacy-review-link', $view);
        $this->assertStringContainsString('wms.legacy-allocation-reviews.index', $view);
        $this->assertStringContainsString('ตรวจ allocation/linkage', $view);
        $this->assertStringContainsString('permission:wms.stock-valuation.view', file_get_contents(base_path('app/Modules/Wms/Routes/web.php')));
    }

    public function test_inventory_valuation_tables_use_server_side_data_tables(): void
    {
        $view = file_get_contents(base_path('app/Modules/Wms/Views/stock-valuation/index.blade.php'));
        $controller = file_get_contents(base_path('app/Modules/Wms/Controllers/StockValuationController.php'));

        $this->assertStringContainsString('window.erpDataTableDefaults', $view);
        $this->assertStringContainsString('DataTables::eloquent($query)', $controller);
        $this->assertStringContainsString('DataTables::query($query)', $controller);
        $this->assertStringNotContainsString('->get()', $controller);
    }

    public function test_inventory_preview_routes_are_read_only_and_permission_gated(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Wms/Routes/web.php'));

        $this->assertStringContainsString("Route::get('/stock-valuation/preflight-summary'", $routes);
        $this->assertStringContainsString("Route::get('/stock-valuation/historical-reconciliation-data'", $routes);
        $this->assertStringContainsString("Route::get('/stock-valuation/recost-health'", $routes);
        $this->assertStringContainsString("Route::post('/stock-valuation/recost-retry'", $routes);
        $this->assertStringContainsString("->middleware('permission:wms.recost.retry')", $routes);
        $this->assertStringNotContainsString("Route::post('/stock-valuation/inventory-post'", $routes);
    }

    public function test_legacy_review_route_permission_and_inventory_sidebar_are_connected(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Wms/Routes/web.php'));
        $sidebar = file_get_contents(base_path('app/Modules/Wms/Views/partials/sidebar.blade.php'));
        $rbac = file_get_contents(base_path('database/seeders/RbacSeeder.php'));

        $this->assertStringContainsString("Route::get('/legacy-allocation-reviews'", $routes);
        $this->assertStringContainsString('permission:wms.cost-allocation-reviews.view', $routes);
        $this->assertStringContainsString('wms.cost-allocation-reviews.view', $sidebar);
        $this->assertStringContainsString("route('wms.legacy-allocation-reviews.index')", $sidebar);
        $this->assertStringContainsString("'wms.cost-allocation-reviews.view'", $rbac);
    }
}
