<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesOrderPhysicalSaleDataTableContractTest extends TestCase
{
    public function test_sales_order_table_exposes_hs_iv_status_and_create_action_with_the_detail_guard(): void
    {
        $base = dirname(__DIR__, 2);
        $controller = file_get_contents($base.'/app/Modules/Pos/Controllers/SalesOrderController.php');
        $view = file_get_contents($base.'/app/Modules/Pos/Views/sales-orders/index.blade.php');

        self::assertStringContainsString("'physicalSales:id,source_id,document_type,document_number,status'", $controller);
        self::assertStringContainsString("->addColumn('physical_sale_status'", $controller);
        self::assertStringContainsString("->addColumn('physical_sale_create_url'", $controller);
        self::assertStringContainsString('<th>HS/IV</th>', $view);
        self::assertStringContainsString('physical_sale_create_url', $view);
    }
}
