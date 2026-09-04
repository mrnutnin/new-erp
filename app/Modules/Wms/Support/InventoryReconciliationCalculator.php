<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class InventoryReconciliationCalculator
{
    public static function totals(string $allocationValue, string $balanceValue, string $glValue, int $unlinkedAllocations = 0, int $pendingAllocations = 0, string $pendingValue = '0', int $lineUnlinked = 0, int $lineMismatched = 0, string $roundingDifference = '0'): array
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
            'pending_allocations' => $pendingAllocations,
            'pending_value' => self::decimal($pendingValue),
            'line_unlinked' => $lineUnlinked,
            'line_mismatched' => $lineMismatched,
            'rounding_difference' => self::decimal($roundingDifference),
            // Period-close must surface stock projection drift as well as GL variance.
            'status' => $unlinkedAllocations > 0 || $pendingAllocations > 0 || $lineUnlinked > 0 || $lineMismatched > 0 || ! $allocationVsGl->isZero() || ! $balanceVsAllocation->isZero()
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
