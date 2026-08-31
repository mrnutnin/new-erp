<?php

namespace App\Modules\Pos\Support;

use App\Modules\Accounting\Support\TaxCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class SalesIntakeCalculator
{
    public function calculate(array $lines, string $taxTreatment, bool $pricesIncludeVat, int $scale): array
    {
        $scale = max(0, min(4, $scale));
        $subtotal = BigDecimal::zero();
        $discount = BigDecimal::zero();
        $taxBase = BigDecimal::zero();
        $tax = BigDecimal::zero();
        $computed = [];

        foreach ($lines as $line) {
            $qty = BigDecimal::of((string) ($line['quantity'] ?? '0'));
            $price = BigDecimal::of((string) ($line['unit_price'] ?? $line['requested_unit_price'] ?? $line['standard_unit_price'] ?? '0'));
            $gross = $qty->multipliedBy($price);
            $lineDiscount = BigDecimal::of((string) ($line['discount_amount'] ?? '0'));
            if ($lineDiscount->isNegative() || $lineDiscount->isGreaterThan($gross)) {
                throw new \InvalidArgumentException('ส่วนลดรายการไม่ถูกต้อง');
            }
            $baseAmount = $gross->minus($lineDiscount);
            $rate = $taxTreatment === 'VAT_OUT' ? (string) ($line['tax_rate'] ?? '0') : '0';
            $taxed = TaxCalculator::calculate($baseAmount->toScale($scale, RoundingMode::HalfUp)->__toString(), $rate, $pricesIncludeVat, $scale);
            $lineBase = BigDecimal::of($taxed['base']);
            $lineTax = BigDecimal::of($taxed['tax']);
            $lineTotal = BigDecimal::of($taxed['gross']);
            $computed[] = array_merge($line, [
                'discount_amount' => $lineDiscount->toScale($scale, RoundingMode::HalfUp)->__toString(),
                'tax_rate' => $rate,
                'tax_base' => $lineBase->toScale($scale, RoundingMode::HalfUp)->__toString(),
                'tax_amount' => $lineTax->toScale($scale, RoundingMode::HalfUp)->__toString(),
                'line_total' => $lineTotal->toScale($scale, RoundingMode::HalfUp)->__toString(),
            ]);
            $subtotal = $subtotal->plus($gross);
            $discount = $discount->plus($lineDiscount);
            $taxBase = $taxBase->plus($lineBase);
            $tax = $tax->plus($lineTax);
        }

        $grand = $taxBase->plus($tax);
        $format = static fn (BigDecimal $v): string => $v->toScale($scale, RoundingMode::HalfUp)->__toString();

        return ['lines' => $computed, 'subtotal' => $format($subtotal), 'discount_amount' => $format($discount), 'tax_base' => $format($taxBase), 'tax_amount' => $format($tax), 'grand_total' => $format($grand)];
    }
}
