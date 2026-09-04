<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\InventoryRoundingCalculator;
use PHPUnit\Framework\TestCase;

final class InventoryRoundingCalculatorTest extends TestCase
{
    public function test_rounding_residual_is_explicit_and_directional(): void
    {
        self::assertSame([
            'exact' => '12.345',
            'posted' => '12.35',
            'difference' => '0.005',
            'direction' => 'LOSS',
        ], InventoryRoundingCalculator::split('12.345'));

        self::assertSame('GAIN', InventoryRoundingCalculator::split('12.344')['direction']);
        self::assertSame('NONE', InventoryRoundingCalculator::split('12.340')['direction']);
    }
}
