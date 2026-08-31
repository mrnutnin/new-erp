<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\StockBalanceCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StockBalanceCalculatorTest extends TestCase
{
    public function test_it_sums_posted_movements_and_applies_reservation(): void
    {
        $balance = StockBalanceCalculator::summarize([
            ['status' => 'POSTED', 'direction' => 'IN', 'base_quantity' => '10.50000000'],
            ['status' => 'DRAFT', 'direction' => 'OUT', 'base_quantity' => '99.00000000'],
            ['status' => 'POSTED', 'direction' => 'OUT', 'base_quantity' => '2.25000000'],
        ], '3.00000000');

        $this->assertSame('8.25000000', $balance['on_hand']);
        $this->assertSame('5.25000000', $balance['available']);
    }

    public function test_negative_reservation_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        StockBalanceCalculator::summarize([], '-1');
    }
}
