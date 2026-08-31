<?php

namespace Tests\Unit;

use App\Modules\Finance\Support\VatRealizationCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VatRealizationCalculatorTest extends TestCase
{
    public function test_it_realizes_tax_proportionally_for_partial_payment(): void
    {
        $this->assertSame([
            'gross' => '50.00', 'tax' => '3.50', 'base' => '46.50',
            'allocated_after' => '50.00', 'tax_realized_after' => '3.50',
        ], VatRealizationCalculator::calculate('100.00', '7.00', '50.00', '0.00'));
    }

    public function test_final_allocation_uses_the_remaining_tax_rounding_remainder(): void
    {
        $this->assertSame('0.01', VatRealizationCalculator::calculate('100.00', '7.01', '33.33', '66.67', '7.00', true)['tax']);
    }

    public function test_it_rejects_overallocation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VatRealizationCalculator::calculate('100.00', '7.00', '60.00', '50.00');
    }
}
