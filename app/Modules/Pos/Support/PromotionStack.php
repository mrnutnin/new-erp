<?php

namespace App\Modules\Pos\Support;

use InvalidArgumentException;

/** Validates the company policy carried by each applied promotion snapshot. */
final class PromotionStack
{
    public static function isValid(array $promotions): bool
    {
        $applied = array_values(array_filter($promotions, fn ($promotion) => is_array($promotion) && isset($promotion['promotion_id'])));
        if (count($applied) <= 1) {
            return true;
        }

        foreach ($applied as $promotion) {
            if (($promotion['stackable'] ?? false) !== true) {
                return false;
            }
        }

        return true;
    }

    public static function assertValid(array $promotions): void
    {
        if (! self::isValid($promotions)) {
            throw new InvalidArgumentException('Promotions can be combined only when every promotion allows stacking.');
        }
    }
}
