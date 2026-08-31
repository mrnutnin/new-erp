<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleSourceAvailabilityContractTest extends TestCase
{
    public function test_active_physical_sale_hides_its_order_from_new_sale_actions(): void
    {
        $base = dirname(__DIR__, 2);
        $controller = file_get_contents($base.'/app/Modules/Pos/Controllers/PhysicalSaleController.php');
        $view = file_get_contents($base.'/app/Modules/Pos/Views/sales-orders/show.blade.php');
        $migration = file_get_contents($base.'/database/migrations/2026_08_29_151000_allow_recreated_physical_sales_after_void.php');

        self::assertStringContainsString("whereDoesntHave('physicalSales'", $controller);
        self::assertStringContainsString("where('status', '!=', 'VOID')", $controller);
        self::assertStringContainsString('$canCreatePhysicalSale', $view);
        self::assertStringContainsString("physicalSales->where('status', '!=', 'VOID')->isEmpty()", $view);
        self::assertStringContainsString("where(['source_type' => \$values['source_type'], 'source_id' => \$source->id])->where('status', '!=', 'VOID')->exists()", $controller);
        self::assertStringContainsString("dropUnique('pos_physical_sales_source_unique')", $migration);
    }
}
