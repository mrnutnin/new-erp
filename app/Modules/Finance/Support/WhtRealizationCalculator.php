<?php

namespace App\Modules\Finance\Support;

use App\Modules\Accounting\Support\JournalBalance;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Calculates WHT recognized by a cash allocation; it does not post GL lines. */
final class WhtRealizationCalculator
{
    public static function calculate(
        string $documentAmount,
        string $withholdingBase,
        string $withholdingAmount,
        string $allocationAmount,
        string $allocatedBefore,
        string $realizedBefore = '0.00',
        bool $finalAllocation = false,
    ): array {
        $amount = self::money($documentAmount);
        $base = self::money($withholdingBase);
        $wht = self::money($withholdingAmount);
        $allocation = self::money($allocationAmount);
        $allocated = self::money($allocatedBefore);
        $realized = self::money($realizedBefore);
        if ($amount === '0.00' || $base === '0.00' || $wht === '0.00' || $allocation === '0.00') {
            throw new InvalidArgumentException('WHT realization ต้องมียอดเอกสาร ฐานภาษี ภาษี และยอดชำระมากกว่าศูนย์');
        }
        if (BigDecimal::of($base)->isGreaterThan(BigDecimal::of($amount)) || BigDecimal::of($wht)->isGreaterThan(BigDecimal::of($base))) {
            throw new InvalidArgumentException('ฐาน WHT หรือยอด WHT เกินยอดเอกสาร');
        }
        $allocatedAfter = JournalBalance::add($allocated, $allocation);
        if (BigDecimal::of($allocated)->isGreaterThan(BigDecimal::of($amount)) || BigDecimal::of($allocatedAfter)->isGreaterThan(BigDecimal::of($amount))) {
            throw new InvalidArgumentException('ยอดชำระสะสมเกินยอดเอกสาร');
        }
        if (BigDecimal::of($realized)->isGreaterThan(BigDecimal::of($wht))) {
            throw new InvalidArgumentException('WHT ที่รับรู้สะสมเกินยอด WHT');
        }

        $recognized = BigDecimal::of($wht)->multipliedBy($allocation)->dividedBy($amount, 2, RoundingMode::HALF_UP)->toScale(2, RoundingMode::HALF_UP)->__toString();
        if ($finalAllocation || $allocatedAfter === $amount) {
            $recognized = JournalBalance::subtract($wht, $realized);
        }
        if (BigDecimal::of(JournalBalance::add($realized, $recognized))->isGreaterThan(BigDecimal::of($wht))) {
            throw new InvalidArgumentException('WHT realization ทำให้ยอดสะสมเกินเอกสาร');
        }

        return [
            'gross' => $allocation,
            'base' => BigDecimal::of($base)->multipliedBy($allocation)->dividedBy($amount, 2, RoundingMode::HALF_UP)->toScale(2, RoundingMode::HALF_UP)->__toString(),
            'tax' => $recognized,
            'allocated_after' => $allocatedAfter,
            'tax_realized_after' => JournalBalance::add($realized, $recognized),
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
