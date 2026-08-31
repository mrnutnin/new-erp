<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\SalesInventoryPostingContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class SalesInventoryPostingContractTest extends TestCase
{
    public function test_service_line_is_explicitly_outside_inventory_posting(): void
    {
        $this->assertSame(['eligible' => false, 'reason' => 'SERVICE_LINE'], SalesInventoryPostingContract::preview(['description' => 'ค่าบริการ']));
    }

    public function test_inventory_line_converts_sale_quantity_to_stock_quantity(): void
    {
        $result = SalesInventoryPostingContract::preview(['item_id' => 4, 'warehouse_id' => 2, 'uom_id' => 10, 'stock_uom_id' => 20, 'quantity' => '2', 'uom_factor' => '12.5', 'business_date' => '2026-08-22']);

        $this->assertTrue($result['eligible']);
        $this->assertSame('25.00000000', $result['stock_quantity']);
        $this->assertSame('2026-08-22', $result['conversion_snapshot']['business_date']);
    }

    public function test_inventory_line_requires_complete_linkage(): void
    {
        $this->expectException(ValidationException::class);
        SalesInventoryPostingContract::preview(['item_id' => 4, 'quantity' => '1', 'uom_factor' => '1', 'business_date' => '2026-08-22']);
    }

    public function test_inventory_line_rejects_non_positive_quantity_or_factor(): void
    {
        $this->expectException(ValidationException::class);
        SalesInventoryPostingContract::preview(['item_id' => 4, 'warehouse_id' => 2, 'uom_id' => 10, 'stock_uom_id' => 20, 'quantity' => '0', 'uom_factor' => '1', 'business_date' => '2026-08-22']);
    }
}
