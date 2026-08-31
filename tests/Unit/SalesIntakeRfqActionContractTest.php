<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesIntakeRfqActionContractTest extends TestCase
{
    public function test_approved_rfq_exposes_quotation_and_order_actions_on_its_source_intake(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Controllers/SalesIntakeController.php');

        self::assertStringContainsString("\$intake->rfq?->status === 'APPROVED'", $controller);
        self::assertStringContainsString("route('pos.sales-quotations.from-rfq', \$intake->rfq)", $controller);
        self::assertStringContainsString("route('pos.sales-orders.from-rfq', \$intake->rfq)", $controller);
    }
}
