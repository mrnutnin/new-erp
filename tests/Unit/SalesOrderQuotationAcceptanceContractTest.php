<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesOrderQuotationAcceptanceContractTest extends TestCase
{
    public function test_quotation_must_be_accepted_before_creating_an_order(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesOrderController.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/sales-quotations/show.blade.php');

        self::assertStringContainsString("if (\$quotation->status !== 'ACCEPTED')", $controller);
        self::assertStringContainsString("@elseif(\$quotation->status === 'ACCEPTED' && auth()->user()->hasPermission('pos.sales-orders.create'))", $view);
    }
}
