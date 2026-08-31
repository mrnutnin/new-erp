<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PosSalesCommissionUiContractTest extends TestCase
{
    public function test_commission_ui_has_view_and_state_transition_guards(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesCommissionController.php');
        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');

        $this->assertStringContainsString("where('branch_id', \$branchId)", $controller);
        $this->assertStringContainsString("whereIn('warehouse_id', \$warehouseIds)", $controller);
        $this->assertStringContainsString('private function authorizedWarehouseIds', $controller);
        $this->assertStringContainsString("abort_unless(\$record->status === 'PENDING'", $controller);
        $this->assertStringContainsString("'status' => 'APPROVED'", $controller);
        $this->assertStringContainsString("'status' => 'REJECTED'", $controller);
        $this->assertStringContainsString('function updateStatus', $controller);
        $this->assertStringContainsString('has_cancelled_payment_batch', $controller);
        $this->assertStringContainsString("['DRAFT', 'SUBMITTED', 'VERIFIED']", $controller);
        $this->assertStringContainsString("'rejection_reason' => \$data['reason']", $controller);
        $this->assertStringContainsString("'pos.sales_commission.approved'", $controller);
        $this->assertStringContainsString("'pos.sales_commission.rejected'", $controller);
        $this->assertStringContainsString('permission:pos.sales-commissions.view', $routes);
        $this->assertStringContainsString('permission:pos.sales-commissions.approve', $routes);
        $this->assertStringContainsString("name('sales-commissions.history')", $routes);
        $this->assertStringContainsString("name('sales-commission-payment-batches.history')", $routes);
    }

    public function test_commission_page_creates_a_combined_batch_before_handing_it_to_finance(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/app/Modules/Pos/Views/sales-commissions/index.blade.php');

        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');

        $this->assertStringContainsString("route('pos.sales-commission-payment-batches.create')", $view);
        $this->assertStringContainsString('commission-payment-batches-table', $view);
        $this->assertStringContainsString("name('sales-commission-payment-batches.create')", $routes);
        $this->assertStringContainsString("name('sales-commission-payment-batches.submit')", $routes);

        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesCommissionController.php');
        $this->assertStringContainsString('function history', $controller);
        $this->assertStringContainsString('Audit Trail', $view);
        $this->assertStringContainsString('js-payment-batch-history', $view);
    }
}
