<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\SalesDocumentCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class SalesDocumentCalculatorTest extends TestCase
{
    public function test_it_recalculates_none_vat_service_totals(): void
    {
        $result = SalesDocumentCalculator::calculate([[
            'description' => 'ค่าบริการ', 'quantity' => '2.5000', 'unit' => 'งาน', 'unit_price' => '100.0000',
            'discount_amount' => '10.00', 'revenue_account_id' => 1, 'tax_code_id' => 1,
        ]]);

        $this->assertSame('250.00', $result['subtotal']);
        $this->assertSame('10.00', $result['discount_amount']);
        $this->assertSame('240.00', $result['total_amount']);
        $this->assertSame('0.00', $result['lines'][0]['tax_amount']);
    }

    public function test_it_rejects_discount_above_line_gross(): void
    {
        $this->expectException(InvalidArgumentException::class);
        SalesDocumentCalculator::calculate([['quantity' => 1, 'unit_price' => 10, 'discount_amount' => 11]]);
    }

    public function test_it_allows_a_fully_discounted_free_good(): void
    {
        $result = SalesDocumentCalculator::calculate([[
            'quantity' => 1, 'unit_price' => 100, 'discount_amount' => 100, 'tax_rate' => '7',
        ]], false, 2);

        $this->assertSame('100.00', $result['subtotal']);
        $this->assertSame('100.00', $result['discount_amount']);
        $this->assertSame('0.00', $result['tax_base']);
        $this->assertSame('0.00', $result['tax_amount']);
        $this->assertSame('0.00', $result['total_amount']);
    }

    public function test_it_calculates_vat_out_exclusive_and_inclusive(): void
    {
        $exclusive = SalesDocumentCalculator::calculate([['quantity' => 1, 'unit_price' => 100, 'discount_amount' => 0, 'tax_rate' => '7']], false, 2);
        $inclusive = SalesDocumentCalculator::calculate([['quantity' => 1, 'unit_price' => 107, 'discount_amount' => 0, 'tax_rate' => '7']], true, 2);

        $this->assertSame('100.00', $exclusive['tax_base']);
        $this->assertSame('7.00', $exclusive['tax_amount']);
        $this->assertSame('107.00', $exclusive['total_amount']);
        $this->assertSame('100.00', $inclusive['tax_base']);
        $this->assertSame('7.00', $inclusive['tax_amount']);
        $this->assertSame('107.00', $inclusive['total_amount']);
    }
}
