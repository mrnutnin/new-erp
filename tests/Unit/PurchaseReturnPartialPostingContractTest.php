<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Support\PurchaseReturnPartialPostingContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PurchaseReturnPartialPostingContractTest extends TestCase
{
    public function test_partial_return_calculates_stock_and_cost_proportionally(): void
    {
        $plan = PurchaseReturnPartialPostingContract::plan([
            'purchase_return_id' => 10, 'goods_receipt_line_id' => 20, 'received_purchase_quantity' => '10',
            'returned_purchase_quantity' => '2.5', 'factor' => '10', 'stock_unit_cost' => '10',
        ]);

        self::assertSame('25.00000000', $plan['stock_quantity']);
        self::assertSame('250.00000000', $plan['total_cost']);
        self::assertSame('0.250000000000', $plan['return_ratio']);
        self::assertTrue($plan['atomic']);
    }

    public function test_partial_return_rejects_quantity_above_receipt(): void
    {
        $this->expectException(ValidationException::class);
        PurchaseReturnPartialPostingContract::plan([
            'purchase_return_id' => 10, 'goods_receipt_line_id' => 20, 'received_purchase_quantity' => '10',
            'returned_purchase_quantity' => '10.00000001', 'factor' => '10', 'stock_unit_cost' => '10',
        ]);
    }
}
