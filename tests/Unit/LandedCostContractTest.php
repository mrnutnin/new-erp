<?php

namespace Tests\Unit;

use Tests\TestCase;

final class LandedCostContractTest extends TestCase
{
    public function test_landed_cost_surface_is_permission_gated_and_has_all_lifecycle_routes(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Purchasing/Routes/web.php'));
        $rbac = file_get_contents(base_path('database/seeders/RbacSeeder.php'));
        $sidebar = file_get_contents(base_path('app/Modules/Purchasing/Views/partials/sidebar.blade.php'));

        foreach (['index', 'create', 'store', 'show', 'submit', 'approve', 'post', 'void'] as $action) {
            self::assertStringContainsString("landed-costs.{$action}", $routes);
        }
        foreach (['view', 'create', 'submit', 'approve', 'post', 'void'] as $action) {
            self::assertStringContainsString("purchasing.landed-costs.{$action}", $rbac);
        }
        self::assertStringContainsString("route('purchasing.landed-costs.index')", $sidebar);
    }

    public function test_landed_cost_allocation_keeps_immutable_wms_linkage(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_03_200000_create_purchasing_landed_costs.php'));
        $posting = file_get_contents(base_path('app/Modules/Purchasing/Services/LandedCostPostingService.php'));

        self::assertStringContainsString("foreignId('wms_cost_allocation_id')->nullable()->unique()", $migration);
        self::assertStringContainsString("'plca_wms_allocation_fk'", $migration);
        self::assertStringContainsString('CostRecalculationRequest', $posting);
        self::assertStringContainsString('RecostGlPostingService', $posting);
        self::assertStringContainsString("'idempotency_key' => \"landed-cost:{\$landedCost->id}:allocation:{\$allocation->id}\"", $posting);
    }
}
