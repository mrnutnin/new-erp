<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Support\PurchaseReturnPartialCostAllocationContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PurchaseReturnPartialCostAllocationContractTest extends TestCase
{
    public function test_avg_partial_cost_uses_only_requested_quantity(): void
    {
        $plan = PurchaseReturnPartialCostAllocationContract::plan('AVG', '2.50000000', '10.00000000');

        self::assertSame('2.50000000', $plan['allocations'][0]['quantity']);
        self::assertSame('25.00000000', $plan['allocations'][0]['value']);
    }

    public function test_fifo_partial_cost_uses_source_layers(): void
    {
        $plan = PurchaseReturnPartialCostAllocationContract::plan('FIFO', '6.00000000', '0', [
            ['quantity' => '4.00000000', 'unit_cost' => '10.00000000'],
            ['quantity' => '5.00000000', 'unit_cost' => '12.00000000'],
        ]);

        self::assertSame('4.00000000', $plan['allocations'][0]['quantity']);
        self::assertSame('2.00000000', $plan['allocations'][1]['quantity']);
    }

    public function test_fifo_partial_cost_rejects_insufficient_layers(): void
    {
        $this->expectException(ValidationException::class);
        PurchaseReturnPartialCostAllocationContract::plan('FIFO', '10', '0', [['quantity' => '2', 'unit_cost' => '10']]);
    }
}
