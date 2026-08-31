<?php

namespace Tests\Unit;

use App\Modules\Accounting\Support\TaxCalculator;
use PHPUnit\Framework\TestCase;

class TaxCalculatorTest extends TestCase
{
    public function test_exclusive_tax_calculation_rounds_line_and_document_amounts(): void
    {
        $this->assertSame(['base' => '100.00', 'tax' => '7.00', 'gross' => '107.00'], TaxCalculator::calculate('100', '7', false, 2));
    }

    public function test_inclusive_tax_calculation_keeps_gross_total(): void
    {
        $this->assertSame(['base' => '93.46', 'tax' => '6.54', 'gross' => '100.00'], TaxCalculator::calculate('100', '7', true, 2));
    }

    public function test_zero_rate_is_none_vat(): void
    {
        $this->assertSame(['base' => '100.000', 'tax' => '0.000', 'gross' => '100.000'], TaxCalculator::calculate('100', '0', false, 3));
    }
}
