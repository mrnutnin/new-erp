<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class InventoryReconciliationCalculator
{
    public static function totals(string $allocationValue, string $balanceValue, string $glValue, int $unlinkedAllocations = 0): array
    {
        $allocation = self::decimal($allocationValue);
        $balance = self::decimal($balanceValue);
        $gl = self::decimal($glValue);

        $allocationVsGl = BigDecimal::of($allocation)->minus($gl);
        $balanceVsAllocation = BigDecimal::of($balance)->minus($allocation);

        return [
            'allocation_value' => $allocation,
            'balance_value' => $balance,
            'gl_inventory_value' => $gl,
            'allocation_vs_gl_difference' => self::out($allocationVsGl),
            'balance_vs_allocation_difference' => self::out($balanceVsAllocation),
            'unlinked_allocations' => $unlinkedAllocations,
            // Period-close must surface stock projection drift as well as GL variance.
            'status' => $unlinkedAllocations > 0 || ! $allocationVsGl->isZero() || ! $balanceVsAllocation->isZero()
                ? 'ต้องตรวจสอบ'
                : 'ตรงกัน',
        ];
    }

    private static function decimal(string $value): string
    {
        return self::out(BigDecimal::of($value));
    }

    private static function out(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }
}
