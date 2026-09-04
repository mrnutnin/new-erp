<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/** Allocates one rounded document total across exact allocation values. */
final class InventoryRoundingAllocator
{
    /** @param list<string|int|float> $exactValues @return list<string> */
    public static function allocate(array $exactValues, int $scale = 2): array
    {
        if ($exactValues === []) {
            return [];
        }

        $exact = array_map(fn ($value) => BigDecimal::of((string) $value)->abs(), $exactValues);
        $posted = array_map(fn (BigDecimal $value) => $value->toScale($scale, RoundingMode::HALF_UP), $exact);
        $target = array_reduce($exact, fn (BigDecimal $sum, BigDecimal $value) => $sum->plus($value), BigDecimal::zero())
            ->toScale($scale, RoundingMode::HALF_UP);
        $current = array_reduce($posted, fn (BigDecimal $sum, BigDecimal $value) => $sum->plus($value), BigDecimal::zero());
        $posted[array_key_last($posted)] = $posted[array_key_last($posted)]->plus($target->minus($current));

        if ($posted[array_key_last($posted)]->isNegative()) {
            throw ValidationException::withMessages(['allocation.value' => 'ไม่สามารถกระจายยอดปัดเศษของต้นทุนให้เป็นค่าบวกได้']);
        }

        return array_map(fn (BigDecimal $value) => $value->toScale($scale, RoundingMode::HALF_UP)->__toString(), $posted);
    }
}
