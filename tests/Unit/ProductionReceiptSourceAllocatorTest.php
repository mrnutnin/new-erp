<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\ProductionReceiptSourceAllocator;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class ProductionReceiptSourceAllocatorTest extends TestCase
{
    public function test_partial_value_is_split_proportionally_and_residual_goes_to_last_allocation(): void
    {
        $rows = [
            ['source_allocation_id' => 2, 'available_quantity' => '3', 'available_value' => '33.33333333'],
            ['source_allocation_id' => 1, 'available_quantity' => '6', 'available_value' => '66.66666667'],
        ];

        $result = (new ProductionReceiptSourceAllocator)->allocate($rows, '33.33333333');

        self::assertSame([1, 2], array_column($result, 'source_allocation_id'));
        self::assertSame('33.33333333', array_reduce($result, fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['consumed_value']), BigDecimal::zero())->toScale(8)->__toString());
        self::assertSame(['2.00000000', '1.00000000'], array_column($result, 'consumed_quantity'));
    }

    public function test_consumed_value_cannot_exceed_available_value(): void
    {
        $this->expectException(ValidationException::class);

        (new ProductionReceiptSourceAllocator)->allocate([
            ['source_allocation_id' => 1, 'available_quantity' => '1', 'available_value' => '10'],
        ], '10.00000001');
    }
}
