<?php

namespace Tests\Unit;

use App\Modules\Finance\Support\WhtRealizationCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WhtRealizationCalculatorTest extends TestCase
{
    public function test_it_realizes_wht_proportionally_and_keeps_final_remainder(): void
    {
        $partial = WhtRealizationCalculator::calculate('1100.00', '1000.00', '100.00', '550.00', '0.00');
        self::assertSame('50.00', $partial['tax']);
        self::assertSame('500.00', $partial['base']);

        $final = WhtRealizationCalculator::calculate('1100.00', '1000.00', '100.00', '550.00', '550.00', '50.00', true);
        self::assertSame('50.00', $final['tax']);
        self::assertSame('100.00', $final['tax_realized_after']);
    }

    public function test_it_rejects_a_wht_base_above_document_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        WhtRealizationCalculator::calculate('100.00', '101.00', '10.00', '50.00', '0.00');
    }
}
