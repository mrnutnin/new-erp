<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PhysicalSalePartialReceiptWhtPreviewContractTest extends TestCase
{
    public function test_partial_payment_preview_uses_the_requested_allocation(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Pos/Controllers/PhysicalSaleReceiptController.php'));

        self::assertStringContainsString("withholdingFor(\$openItem, \$allocation, \$values['settlement_date'])", $controller);
        self::assertStringNotContainsString("\$item->remaining_amount, (string) \$allocated", $controller);
    }
}
