<?php

namespace App\Modules\Finance\Support;

use App\Modules\Accounting\Support\JournalBalance;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Calculates VAT recognized by a cash allocation. It does not post GL lines.
 */
final class VatRealizationCalculator
{
    public static function calculate(
        string $originalGross,
        string $originalTax,
        string $allocationAmount,
        string $allocatedBefore,
        string $taxRealizedBefore = '0.00',
        bool $finalAllocation = false,
    ): array {
        $gross = self::money($originalGross);
        $tax = self::money($originalTax);
        $allocation = self::money($allocationAmount);
        $allocated = self::money($allocatedBefore);
        $realized = self::money($taxRealizedBefore);
        if ($gross === '0.00' || $tax === '0.00' || $allocation === '0.00') {
            throw new InvalidArgumentException('VAT realization ต้องมียอดเอกสาร ภาษี และยอดชำระมากกว่าศูนย์');
        }
        $allocatedAfter = JournalBalance::add($allocated, $allocation);
        if (BigDecimal::of($tax)->isGreaterThan(BigDecimal::of($gross))
            || BigDecimal::of($allocated)->isGreaterThan(BigDecimal::of($gross))
            || BigDecimal::of($allocatedAfter)->isGreaterThan(BigDecimal::of($gross))) {
            throw new InvalidArgumentException('ยอดชำระสะสมเกินยอดเอกสาร');
        }
        if ($realized > $tax) {
            throw new InvalidArgumentException('VAT ที่รับรู้สะสมเกินยอดภาษี');
        }

        $recognizedTax = BigDecimal::of($tax)
            ->multipliedBy($allocation)
            ->dividedBy($gross, 2, RoundingMode::HALF_UP)
            ->toScale(2, RoundingMode::HALF_UP)
            ->__toString();
        if ($finalAllocation || $allocatedAfter === $gross) {
            $recognizedTax = JournalBalance::subtract($tax, $realized);
        }
        if (BigDecimal::of(JournalBalance::add($realized, $recognizedTax))->isGreaterThan(BigDecimal::of($tax))) {
            throw new InvalidArgumentException('VAT realization ทำให้ยอดภาษีสะสมเกินเอกสาร');
        }

        return [
            'gross' => $allocation,
            'tax' => $recognizedTax,
            'base' => JournalBalance::subtract($allocation, $recognizedTax),
            'allocated_after' => $allocatedAfter,
            'tax_realized_after' => JournalBalance::add($realized, $recognizedTax),
        ];
    }

    private static function money(string $value): string
    {
        $money = JournalBalance::decimal($value);
        if (str_starts_with($money, '-')) {
            throw new InvalidArgumentException('จำนวนเงินต้องไม่ติดลบ');
        }

        return $money;
    }
}
