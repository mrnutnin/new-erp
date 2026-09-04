<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Wms\Support\CostingCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class PurchaseReturnPartialCostAllocationContract
{
    public static function plan(string $method, string $quantity, string $averageUnitCost = '0', array $layers = []): array
    {
        $quantityDecimal = BigDecimal::of($quantity)->toScale(8, RoundingMode::HALF_UP);
        if ($quantityDecimal->isLessThanOrEqualTo(0)) {
            throw ValidationException::withMessages(['quantity' => 'Partial cost allocation quantity ต้องมากกว่า 0']);
        }
        $method = strtoupper($method);
        if ($method === 'AVG') {
            $cost = BigDecimal::of($averageUnitCost)->toScale(8, RoundingMode::HALF_UP);
            if ($cost->isNegative()) {
                throw ValidationException::withMessages(['average_unit_cost' => 'Average unit cost ต้องไม่ติดลบ']);
            }
            return ['method' => 'AVG', 'allocations' => [['quantity' => $quantityDecimal->__toString(), 'unit_cost' => $cost->__toString(), 'value' => $quantityDecimal->multipliedBy($cost)->toScale(8, RoundingMode::HALF_UP)->__toString()]], 'atomic' => true];
        }
        if ($method !== 'FIFO' || $layers === []) {
            throw ValidationException::withMessages(['method' => 'Partial cost allocation ต้องใช้ AVG หรือ FIFO พร้อม cost source']);
        }
        try {
            $allocations = CostingCalculator::fifoIssue($layers, $quantityDecimal->__toString());
        } catch (\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['layers' => $exception->getMessage()]);
        }

        return ['method' => 'FIFO', 'allocations' => $allocations, 'atomic' => true];
    }
}
