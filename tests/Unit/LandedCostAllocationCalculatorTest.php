<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Support\LandedCostAllocationCalculator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class LandedCostAllocationCalculatorTest extends TestCase
{
    public function test_value_allocation_is_decimal_safe_and_balances_rounding_on_last_target(): void
    {
        $result = LandedCostAllocationCalculator::preview(
            [['id' => 10, 'value' => '100.00'], ['id' => 11, 'value' => '200.00'], ['id' => 12, 'value' => '700.00']],
            [['id' => 20, 'amount' => '100.00']],
        );

        self::assertSame('100.00000000', $result['total']);
        self::assertSame(['10.00000000', '20.00000000', '70.00000000'], array_column($result['allocations'], 'amount'));
    }

    public function test_multiple_charges_and_quantity_basis_are_supported(): void
    {
        $result = LandedCostAllocationCalculator::preview(
            [['id' => 1, 'quantity' => '1'], ['id' => 2, 'quantity' => '3']],
            [['id' => 5, 'amount' => '10.00'], ['id' => 6, 'amount' => '2.00']],
            'QUANTITY',
        );

        self::assertSame('12.00000000', $result['total']);
        self::assertSame(['2.50000000', '7.50000000', '0.50000000', '1.50000000'], array_column($result['allocations'], 'amount'));
    }

    public function test_invalid_target_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        LandedCostAllocationCalculator::preview([['id' => 1, 'value' => '0']], [['id' => 1, 'amount' => '1']]);
    }
}
