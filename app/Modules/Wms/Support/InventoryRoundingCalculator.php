<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Keeps the high precision WMS value separate from the amount posted to GL.
 * GL is normally configured to two decimal places; the residual is never
 * silently discarded and can be posted to a rounding gain/loss account.
 */
final class InventoryRoundingCalculator
{
    /**
     * @return array{exact:string,posted:string,difference:string,direction:string}
     */
    public static function split(string|int|float $value, int $scale = 2): array
    {
        $exact = BigDecimal::of((string) $value);
        $posted = $exact->toScale($scale, RoundingMode::HALF_UP);
        $difference = $posted->minus($exact);

        return [
            'exact' => $exact->__toString(),
            'posted' => $posted->__toString(),
            'difference' => $difference->__toString(),
            'direction' => $difference->isZero() ? 'NONE' : ($difference->isPositive() ? 'LOSS' : 'GAIN'),
        ];
    }
}
