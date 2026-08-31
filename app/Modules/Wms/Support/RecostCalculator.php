<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class RecostCalculator
{
    public static function resolve(string $pendingQuantity, string $receiptQuantity, string $provisionalCost, string $actualCost): array
    {
        foreach ([$pendingQuantity, $receiptQuantity, $provisionalCost, $actualCost] as $value) {
            if (! preg_match('/^\d+(?:\.\d{1,8})?$/', $value)) {
                throw new InvalidArgumentException('Recost value ต้องเป็นเลขทศนิยมไม่ติดลบ');
            }
        }
        $pending = BigDecimal::of($pendingQuantity)->toScale(8, RoundingMode::UNNECESSARY);
        $receipt = BigDecimal::of($receiptQuantity)->toScale(8, RoundingMode::UNNECESSARY);
        $take = $pending->isLessThan($receipt) ? $pending : $receipt;
        $delta = $take->multipliedBy(BigDecimal::of($actualCost)->minus(BigDecimal::of($provisionalCost)));

        return ['quantity' => self::out($take), 'cost_delta' => self::out($delta)];
    }

    private static function out(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }
}
