<?php

namespace App\Modules\Pos\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class SalesDiscountApproval
{
    /**
     * Price-list and promotion discounts are approved commercial terms. Only
     * discounts over that evidence are evaluated against the threshold.
     */
    public static function assess(array $lines, string $thresholdPercent): array
    {
        $gross = BigDecimal::zero();
        $priceList = BigDecimal::zero();
        $promotion = BigDecimal::zero();
        $manual = BigDecimal::zero();

        foreach ($lines as $line) {
            $lineGross = BigDecimal::of((string) $line['quantity'])->multipliedBy((string) $line['unit_price'])->toScale(2, RoundingMode::HALF_UP);
            $lineDiscount = BigDecimal::of((string) ($line['discount_amount'] ?? '0'))->toScale(2, RoundingMode::HALF_UP);
            $snapshot = is_array($line['price_snapshot'] ?? null) ? $line['price_snapshot'] : null;
            $standardDiscount = $snapshot && ($snapshot['source'] ?? null) === 'PROMOTION'
                ? BigDecimal::of(PromotionSnapshot::discountAmount($snapshot, (string) $line['quantity']))
                : ($snapshot ? BigDecimal::of(PriceListSnapshot::discountAmount($snapshot, (string) $line['quantity'])) : BigDecimal::zero());

            $gross = $gross->plus($lineGross);
            if ($snapshot && ($snapshot['source'] ?? null) === 'PROMOTION') {
                $promotion = $promotion->plus($lineDiscount->isLessThan($standardDiscount) ? $lineDiscount : $standardDiscount);
            } else {
                $priceList = $priceList->plus($lineDiscount->isLessThan($standardDiscount) ? $lineDiscount : $standardDiscount);
            }
            $manual = $manual->plus($lineDiscount->minus($standardDiscount)->isPositive() ? $lineDiscount->minus($standardDiscount) : BigDecimal::zero());
        }

        $rate = $gross->isZero()
            ? BigDecimal::zero()
            : $manual->multipliedBy(100)->dividedBy($gross, 4, RoundingMode::HALF_UP);
        $threshold = BigDecimal::of($thresholdPercent)->toScale(2, RoundingMode::HALF_UP);

        return [
            'gross_amount' => $gross->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'price_list_discount_amount' => $priceList->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'promotion_discount_amount' => $promotion->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'manual_discount_amount' => $manual->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'manual_discount_percent' => $rate->__toString(),
            'threshold_percent' => $threshold->__toString(),
            'requires_reason' => $manual->isPositive() && $rate->isGreaterThan($threshold),
        ];
    }
}
