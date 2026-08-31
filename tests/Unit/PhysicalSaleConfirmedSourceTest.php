<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PhysicalSaleConfirmedSourceTest extends TestCase
{
    public function test_physical_sale_requires_a_confirmed_sales_order_source(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/PhysicalSaleController.php');
        $orderView = file_get_contents($root.'/app/Modules/Pos/Views/sales-orders/show.blade.php');

        $this->assertStringContainsString("where('status', 'CONFIRMED')", $controller);
        $this->assertStringContainsString("route('pos.physical-sales.create', ['sales_order_id' => \$order->id])", $orderView);
    }
}
