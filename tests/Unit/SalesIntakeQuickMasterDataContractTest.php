<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesIntakeQuickMasterDataContractTest extends TestCase
{
    public function test_intake_form_has_quick_customer_and_address_actions(): void
    {
        $base = dirname(__DIR__, 2);
        $form = file_get_contents("{$base}/app/Modules/Pos/Views/sales-intakes/form.blade.php");
        $routes = file_get_contents("{$base}/app/Modules/Pos/Routes/web.php");
        $customerController = file_get_contents("{$base}/app/Modules/Pos/Controllers/CustomerController.php");

        self::assertStringContainsString('js-quick-customer', $form);
        self::assertStringContainsString('js-quick-address', $form);
        self::assertStringContainsString("Route::post('/customers/{customer}/addresses'", $routes);
        self::assertStringContainsString("Route::get('/customers/quick-options'", $routes);
        self::assertStringContainsString('function quickOptions', $customerController);
        self::assertStringContainsString('hard_match', $customerController);
        self::assertStringContainsString('DocumentSequenceService $sequences', $customerController);
        self::assertStringContainsString("'document_type' => 'CUSTOMER'", file_get_contents("{$base}/database/migrations/2026_08_29_120000_add_global_customer_code_sequence.php"));
    }
}
