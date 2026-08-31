<?php

namespace App\Modules\Pos\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class SalesQuotationAmounts
{
    /** @param array<int, array{quantity:mixed,unit_price:mixed,discount_amount:mixed}> $lines */
    public static function calculate(array $lines): array
    {
        $subtotal = BigDecimal::zero();
        $discount = BigDecimal::zero();
        $normalized = [];

        foreach ($lines as $line) {
            $quantity = BigDecimal::of((string) $line['quantity']);
            $unitPrice = BigDecimal::of((string) $line['unit_price'])->toScale(2, RoundingMode::HALF_UP);
            $lineDiscount = BigDecimal::of((string) ($line['discount_amount'] ?? '0'))->toScale(2, RoundingMode::HALF_UP);
            $gross = $quantity->multipliedBy($unitPrice)->toScale(2, RoundingMode::HALF_UP);
            if ($lineDiscount->isLessThan(BigDecimal::zero()) || $lineDiscount->isGreaterThan($gross)) {
                throw new \InvalidArgumentException('ส่วนลดต้องไม่ติดลบและไม่เกินยอดบรรทัด');
            }
            $normalized[] = [
                'unit_price' => $unitPrice->__toString(),
                'discount_amount' => $lineDiscount->__toString(),
                'line_total' => $gross->minus($lineDiscount)->toScale(2, RoundingMode::HALF_UP)->__toString(),
            ];
            $subtotal = $subtotal->plus($gross);
            $discount = $discount->plus($lineDiscount);
        }

        return [
            'lines' => $normalized,
            'subtotal' => $subtotal->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'discount_amount' => $discount->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'total_amount' => $subtotal->minus($discount)->toScale(2, RoundingMode::HALF_UP)->__toString(),
        ];
    }
}
