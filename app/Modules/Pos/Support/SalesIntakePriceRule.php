<?php

namespace App\Modules\Pos\Support;

final class SalesIntakePriceRule
{
    public static function requiresRfq(?string $requested, ?string $standard): bool
    {
        if ($requested === null || $standard === null || ! function_exists('bccomp')) {
            return false;
        }

        return bccomp($requested, $standard, 4) < 0;
    }
}
