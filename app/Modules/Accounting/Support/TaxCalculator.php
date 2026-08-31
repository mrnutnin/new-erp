<?php

namespace App\Modules\Accounting\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class TaxCalculator
{
    public static function calculate(string $amount, string $rate, bool $inclusive, int $scale): array
    {
        if ($scale < 0 || $scale > 8) {
            throw new \InvalidArgumentException('Tax scale must be between 0 and 8.');
        }

        $amount = self::round($amount, $scale);
        $rate = self::round($rate, 8);
        if (BigDecimal::of($rate)->isZero() || $amount === '0') {
            return ['base' => self::round($amount, $scale), 'tax' => self::zero($scale), 'gross' => self::round($amount, $scale)];
        }

        $hundred = BigDecimal::of('100');
        $rateFactor = BigDecimal::of('1')->plus(BigDecimal::of($rate)->dividedBy($hundred, 12, RoundingMode::HALF_UP));
        if ($inclusive) {
            $gross = BigDecimal::of($amount)->toScale($scale, RoundingMode::HALF_UP);
            $base = $gross->dividedBy($rateFactor, $scale, RoundingMode::HALF_UP);
            $tax = $gross->minus($base);

            return ['base' => $base->toScale($scale, RoundingMode::HALF_UP)->__toString(), 'tax' => $tax->toScale($scale, RoundingMode::HALF_UP)->__toString(), 'gross' => $gross->__toString()];
        }

        $base = BigDecimal::of($amount)->toScale($scale, RoundingMode::HALF_UP);
        $tax = $base->multipliedBy(BigDecimal::of($rate))->dividedBy($hundred, $scale, RoundingMode::HALF_UP);
        $gross = $base->plus($tax)->toScale($scale, RoundingMode::HALF_UP);

        return ['base' => $base->__toString(), 'tax' => $tax->__toString(), 'gross' => $gross->__toString()];
    }

    private static function round(string $value, int $scale): string
    {
        return BigDecimal::of($value)->toScale($scale, RoundingMode::HALF_UP)->__toString();
    }

    private static function zero(int $scale): string
    {
        return BigDecimal::zero()->toScale($scale)->__toString();
    }
}
