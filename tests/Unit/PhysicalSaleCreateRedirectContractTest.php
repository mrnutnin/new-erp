<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleCreateRedirectContractTest extends TestCase
{
    public function test_existing_source_order_redirects_to_its_active_sale(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Controllers/PhysicalSaleController.php');

        self::assertStringContainsString("where(['source_type' => 'SALES_ORDER', 'source_id' => \$sourceOrderId])", $controller);
        self::assertStringContainsString("where('status', '!=', 'VOID')", $controller);
        self::assertStringContainsString("redirect()->route('pos.physical-sales.show', \$existing)", $controller);
    }
}
