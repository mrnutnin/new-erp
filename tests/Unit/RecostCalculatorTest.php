<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\CostingCalculator;
use App\Modules\Wms\Support\RecostCalculator;
use InvalidArgumentException;
use Tests\TestCase;

class RecostCalculatorTest extends TestCase
{
    public function test_recost_delta_is_decimal_safe_and_repeatable_for_partial_receipts(): void
    {
        $first = RecostCalculator::resolve('3', '1.25', '10.12345678', '10.22345678');
        $second = RecostCalculator::resolve('1.75', '2', '10.12345678', '10.22345678');

        $this->assertSame('1.25000000', $first['quantity']);
        $this->assertSame('0.12500000', $first['cost_delta']);
        $this->assertSame('1.75000000', $second['quantity']);
        $this->assertSame('0.17500000', $second['cost_delta']);
    }

    public function test_receipt_resolves_only_the_pending_quantity_and_returns_delta(): void
    {
        $result = RecostCalculator::resolve('3', '5', '10.25', '12');

        $this->assertSame('3.00000000', $result['quantity']);
        $this->assertSame('5.25000000', $result['cost_delta']);
    }

    public function test_average_accepts_negative_opening_quantity_when_receipt_closes_provisional_stock(): void
    {
        $result = CostingCalculator::average('-50', '20', '50', '10');

        $this->assertSame('0.00000000', $result['quantity']);
        $this->assertSame('0.00000000', $result['value']);
    }

    public function test_invalid_negative_cost_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RecostCalculator::resolve('1', '1', '10', '-1');
    }
}
