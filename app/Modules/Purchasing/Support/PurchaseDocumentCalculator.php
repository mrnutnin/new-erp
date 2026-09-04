<?php

namespace App\Modules\Purchasing\Support;

use App\Modules\Accounting\Support\TaxCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class PurchaseDocumentCalculator
{
    public static function calculate(array $lines, string $taxTreatment = 'NONE_VAT', bool $pricesIncludeVat = false, int $taxDecimals = 2, int $inputDecimals = 4): array
    {
        if ($lines === [] || count($lines) > 100) {
            throw new InvalidArgumentException('Document must contain between 1 and 100 lines.');
        }

        $subtotal = BigDecimal::zero()->toScale(2);
        $calculated = [];
        foreach ($lines as $index => $line) {
            try {
                $quantity = BigDecimal::of((string) ($line['quantity'] ?? ''))->strippedOfTrailingZeros();
                $unitPrice = BigDecimal::of((string) ($line['unit_price'] ?? ''))->strippedOfTrailingZeros();
                $discount = BigDecimal::of((string) ($line['discount_amount'] ?? '0'))->strippedOfTrailingZeros();
            } catch (\Throwable) {
                throw new InvalidArgumentException("Line {$index} contains invalid numbers.");
            }
            if ($quantity->isLessThanOrEqualTo(0) || $quantity->getScale() > $inputDecimals || $unitPrice->isNegative() || $unitPrice->getScale() > $inputDecimals || $discount->isNegative() || $discount->getScale() > $inputDecimals) {
                throw new InvalidArgumentException("Line {$index} contains invalid precision or negative values.");
            }

            $beforeDiscount = $quantity->multipliedBy($unitPrice)->toScale(2, RoundingMode::HALF_UP);
            if ($discount->isGreaterThan($beforeDiscount)) {
                throw new InvalidArgumentException("Line {$index} discount exceeds its amount.");
            }
            $entered = $beforeDiscount->minus($discount)->toScale(2, RoundingMode::HALF_UP);
            $rate = (string) ($line['tax_rate'] ?? '0');
            $tax = strtoupper($taxTreatment) === 'VAT_IN'
                ? TaxCalculator::calculate((string) $entered, $rate, $pricesIncludeVat, $taxDecimals)
                : ['base' => (string) $entered, 'tax' => '0.00', 'gross' => (string) $entered];
            $net = BigDecimal::of($tax['base'])->toScale(2, RoundingMode::HALF_UP);
            $subtotal = $subtotal->plus($net);
            $calculated[] = [
                ...$line,
                'quantity' => $quantity->toScale($inputDecimals)->__toString(),
                'unit_price' => $unitPrice->toScale($inputDecimals)->__toString(),
                'discount_amount' => $discount->toScale($inputDecimals)->__toString(),
                'net_amount' => $net->__toString(),
                'tax_rate' => BigDecimal::of($rate)->toScale(4)->__toString(),
                'tax_base' => $net->__toString(),
                'tax_amount' => $tax['tax'],
                'gross_amount' => $tax['gross'],
            ];
        }

        return [
            'lines' => $calculated,
            'subtotal' => $subtotal->__toString(),
            'tax_amount' => array_reduce($calculated, fn (string $total, array $line) => (string) BigDecimal::of($total)->plus($line['tax_amount'])->toScale(2, RoundingMode::HALF_UP), '0.00'),
            'gross_amount' => array_reduce($calculated, fn (string $total, array $line) => (string) BigDecimal::of($total)->plus($line['gross_amount'])->toScale(2, RoundingMode::HALF_UP), '0.00'),
            'rounding_amount' => '0.00',
        ];
    }
}
