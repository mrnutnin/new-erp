<?php

namespace App\Modules\Pos\Support;

use App\Modules\Accounting\Support\TaxCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class SalesDocumentCalculator
{
    public static function calculate(array $lines, bool $pricesIncludeVat = false, int $taxDecimals = 2): array
    {
        $subtotal = BigDecimal::zero();
        $discount = BigDecimal::zero();
        $normalized = [];

        foreach ($lines as $index => $line) {
            $quantity = BigDecimal::of((string) $line['quantity']);
            $unitPrice = BigDecimal::of((string) $line['unit_price']);
            $lineDiscount = BigDecimal::of((string) ($line['discount_amount'] ?? 0))->toScale(2, RoundingMode::HALF_UP);
            $gross = $quantity->multipliedBy($unitPrice)->toScale(2, RoundingMode::HALF_UP);
            if ($quantity->isLessThanOrEqualTo(0) || $unitPrice->isNegative() || $lineDiscount->isNegative() || $lineDiscount->isGreaterThan($gross)) {
                throw new InvalidArgumentException('ยอดบรรทัดที่ '.($index + 1).' ไม่ถูกต้อง');
            }
            $entered = $gross->minus($lineDiscount)->toScale(2, RoundingMode::HALF_UP);
            $rate = (string) ($line['tax_rate'] ?? '0');
            $tax = TaxCalculator::calculate((string) $entered, $rate, $pricesIncludeVat, $taxDecimals);
            $base = BigDecimal::of($tax['base'])->toScale(2, RoundingMode::HALF_UP);
            $subtotal = $subtotal->plus($gross);
            $discount = $discount->plus($lineDiscount);
            $normalized[] = [...$line, 'tax_rate' => BigDecimal::of($rate)->toScale(4)->__toString(), 'tax_base' => (string) $base, 'tax_amount' => $tax['tax'], 'line_total' => $tax['gross']];
        }

        $taxBase = collect($normalized)->reduce(fn (BigDecimal $sum, array $line) => $sum->plus($line['tax_base']), BigDecimal::zero())->toScale(2, RoundingMode::HALF_UP);
        $taxAmount = collect($normalized)->reduce(fn (BigDecimal $sum, array $line) => $sum->plus($line['tax_amount']), BigDecimal::zero())->toScale(2, RoundingMode::HALF_UP);
        $total = collect($normalized)->reduce(fn (BigDecimal $sum, array $line) => $sum->plus($line['line_total']), BigDecimal::zero())->toScale(2, RoundingMode::HALF_UP);

        return [
            'lines' => $normalized,
            'subtotal' => (string) $subtotal->toScale(2, RoundingMode::HALF_UP),
            'discount_amount' => (string) $discount->toScale(2, RoundingMode::HALF_UP),
            'tax_base' => (string) $taxBase,
            'tax_amount' => (string) $taxAmount,
            'total_amount' => (string) $total,
        ];
    }
}
