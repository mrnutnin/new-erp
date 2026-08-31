<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SalesIntakeAddressSelectionTest extends TestCase
{
    public function test_sales_intake_has_a_customer_address_endpoint_and_two_address_selects(): void
    {
        $routes = file_get_contents(__DIR__.'/../../app/Modules/POS/Routes/web.php');
        $form = file_get_contents(__DIR__.'/../../app/Modules/POS/Views/sales-intakes/form.blade.php');

        $this->assertStringContainsString('sales-intakes/party-addresses', $routes);
        $this->assertStringContainsString('js-billing-address', $form);
        $this->assertStringContainsString('js-shipping-address', $form);
        $this->assertStringContainsString('loadAddresses', $form);
        $this->assertStringContainsString("window.erpAjaxForm({form:'[data-sales-intake-form]'", $form);
    }
}
