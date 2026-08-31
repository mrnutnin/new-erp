<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\SalesQuotationAmounts;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SalesQuotationAmountsTest extends TestCase
{
    public function test_calculates_line_totals_and_document_totals(): void
    {
        $result = SalesQuotationAmounts::calculate([
            ['quantity' => '2.0000', 'unit_price' => '125.50', 'discount_amount' => '10.00'],
            ['quantity' => '1.0000', 'unit_price' => '50', 'discount_amount' => '0'],
        ]);

        $this->assertSame('241.00', $result['lines'][0]['line_total']);
        $this->assertSame('291.00', $result['total_amount']);
        $this->assertSame('10.00', $result['discount_amount']);
    }

    public function test_rejects_discount_above_gross(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SalesQuotationAmounts::calculate([['quantity' => '1', 'unit_price' => '10', 'discount_amount' => '10.01']]);
    }
}
