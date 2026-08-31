<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleReceiptUiContractTest extends TestCase
{
    public function test_pos_receipt_routes_form_and_input_boundary_are_declared(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/PhysicalSaleReceiptController.php');
        $request = file_get_contents($root.'/app/Modules/Pos/Requests/ReceivePhysicalSalePaymentRequest.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/physical-sales/receive-payment.blade.php');
        $rbac = file_get_contents($root.'/database/seeders/RbacSeeder.php');

        self::assertStringContainsString("Route::get('/physical-sales/{physicalSale}/receive-payment'", $routes);
        self::assertStringContainsString("Route::post('/physical-sales/{physicalSale}/receive-payment'", $routes);
        self::assertStringContainsString('receive-payment/summary', $routes);
        self::assertStringContainsString('permission:pos.physical-sales.receive-payment', $routes);
        self::assertStringContainsString('pos.physical-sales.receive-payment', $rbac);
        self::assertStringContainsString('paymentOpenItem', $controller);
        self::assertStringContainsString('PhysicalSaleReceiptService', $controller);
        self::assertStringContainsString("'withholding_amount' => ['prohibited']", $request);
        self::assertStringContainsString("'allocation_amount' => ['required'", $request);
        self::assertStringContainsString('name="tenders[0][bank_account_id]"', $view);
        self::assertStringContainsString('name="allocation_amount"', $view);
        self::assertStringContainsString('id="allocation-amount"', $view);
        self::assertStringContainsString('data-summary-url', $view);
        self::assertStringContainsString('function refreshSummary()', $view);
        self::assertStringContainsString('id="add-tender"', $view);
        self::assertStringContainsString('overpayment-note', $view);
        self::assertStringContainsString('window.erpAjaxForm', $view);
    }
}
