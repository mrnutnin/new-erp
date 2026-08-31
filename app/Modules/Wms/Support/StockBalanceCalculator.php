<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class StockBalanceCalculator
{
    /** @param iterable<array|object> $movements */
    public static function summarize(iterable $movements, string $reserved = '0'): array
    {
        $onHand = BigDecimal::zero();
        foreach ($movements as $movement) {
            $status = is_array($movement) ? ($movement['status'] ?? null) : ($movement->status ?? null);
            if ($status !== 'POSTED') {
                continue;
            }
            $direction = is_array($movement) ? ($movement['direction'] ?? null) : ($movement->direction ?? null);
            $quantity = is_array($movement) ? ($movement['base_quantity'] ?? null) : ($movement->base_quantity ?? null);
            if (! in_array($direction, ['IN', 'OUT'], true) || ! is_string((string) $quantity)) {
                throw new InvalidArgumentException('Movement ต้องมี direction และ base_quantity ที่ถูกต้อง');
            }
            $amount = BigDecimal::of((string) $quantity)->toScale(8, RoundingMode::UNNECESSARY);
            $onHand = $direction === 'IN' ? $onHand->plus($amount) : $onHand->minus($amount);
        }
        $reservedAmount = BigDecimal::of($reserved)->toScale(8, RoundingMode::UNNECESSARY);
        if ($reservedAmount->isNegative()) {
            throw new InvalidArgumentException('Reserved quantity ต้องไม่ติดลบ');
        }

        return [
            'on_hand' => $onHand->toScale(8)->__toString(),
            'reserved' => $reservedAmount->__toString(),
            'available' => $onHand->minus($reservedAmount)->toScale(8)->__toString(),
        ];
    }
}
