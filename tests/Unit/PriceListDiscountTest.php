<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\PriceListSnapshot;
use PHPUnit\Framework\TestCase;

class PriceListDiscountTest extends TestCase
{
    public function test_it_calculates_the_price_list_discount_for_the_line_quantity(): void
    {
        $snapshot = ['unit_price' => '125.5000', 'discount_percent' => '2.5000'];

        $this->assertSame('6.28', PriceListSnapshot::discountAmount($snapshot, '2.0000'));
    }
}
