<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosSalesCommissionPlanContractTest extends TestCase
{
    public function test_commission_plan_contract_has_bases_rate_dates_and_scoped_recipients(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = file_get_contents($root.'/database/migrations/2026_08_30_200000_create_pos_sales_commission_plans_tables.php');
        $request = file_get_contents($root.'/app/Modules/Pos/Requests/SaveSalesCommissionPlanRequest.php');

        $this->assertStringContainsString("Schema::create('pos_sales_commission_plans'", $migration);
        $this->assertStringContainsString("Schema::create('pos_sales_commission_plan_assignments'", $migration);
        $this->assertStringContainsString("['POSTED_SALE', 'COLLECTED_RECEIPT', 'GROSS_PROFIT']", $request);
        $this->assertStringContainsString("'rate' => ['required', 'numeric', 'min:0', 'max:100']", $request);
        $this->assertStringContainsString("'assignments.*.branch_id' => ['required'", $request);
    }

    public function test_commission_plan_routes_are_permission_gated(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');
        $sidebar = file_get_contents($root.'/app/Modules/Pos/Views/partials/sidebar.blade.php');

        $this->assertStringContainsString('permission:pos.commission-plans.view', $routes);
        $this->assertStringContainsString('permission:pos.commission-plans.create', $routes);
        $this->assertStringContainsString('permission:pos.commission-plans.update', $routes);
        $this->assertStringContainsString('permission:pos.commission-plans.delete', $routes);
        $this->assertStringContainsString('ตั้งค่าคอมมิชชั่นขาย', $sidebar);
    }
}
