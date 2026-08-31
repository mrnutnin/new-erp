<?php

namespace App\Modules\Pos\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/** Allocates one document promotion across eligible sales lines. */
final class DocumentPromotionDiscountAllocator
{
    /**
     * @param  array<int, array{line_number:int, amount:string}>  $eligibleLines
     * @param  array{bill_discount_amount?:string|null, bill_discount_percent?:string|null}  $promotion
     * @return array{discount_amount:string, allocations:array<int, array{line_number:int, discount_amount:string}>}
     */
    public static function allocate(array $eligibleLines, array $promotion): array
    {
        $lines = self::lines($eligibleLines);
        $total = array_reduce($lines, fn (BigDecimal $sum, array $line) => $sum->plus($line['amount']), BigDecimal::zero());
        $discount = self::discount($promotion, $total);

        if ($discount->isGreaterThan($total)) {
            throw new InvalidArgumentException('ส่วนลดท้ายบิลเกินยอดรายการที่ใช้ส่วนลดได้');
        }

        $allocated = BigDecimal::zero();
        foreach ($lines as &$line) {
            $line['discount'] = $discount->multipliedBy($line['amount'])->dividedBy($total, 6, RoundingMode::HALF_UP)->toScale(2, RoundingMode::DOWN);
            $line['remainder'] = $discount->multipliedBy($line['amount'])->dividedBy($total, 6, RoundingMode::HALF_UP)->minus($line['discount']);
            $allocated = $allocated->plus($line['discount']);
        }
        unset($line);

        $cents = (int) $discount->minus($allocated)->multipliedBy(100)->toInt();
        usort($lines, fn (array $left, array $right) => $right['remainder']->compareTo($left['remainder']) ?: $left['line_number'] <=> $right['line_number']);
        for ($index = 0; $index < $cents; $index++) {
            $lines[$index]['discount'] = $lines[$index]['discount']->plus('0.01');
        }
        usort($lines, fn (array $left, array $right) => $left['line_number'] <=> $right['line_number']);

        return [
            'discount_amount' => $discount->__toString(),
            'allocations' => array_map(fn (array $line) => [
                'line_number' => $line['line_number'],
                'discount_amount' => $line['discount']->__toString(),
            ], $lines),
        ];
    }

    private static function discount(array $promotion, BigDecimal $total): BigDecimal
    {
        $hasAmount = ($promotion['bill_discount_amount'] ?? null) !== null;
        $hasPercent = ($promotion['bill_discount_percent'] ?? null) !== null;
        if ($hasAmount === $hasPercent) {
            throw new InvalidArgumentException('Promotion ท้ายบิลต้องมีส่วนลดเพียงหนึ่งรูปแบบ');
        }

        $discount = $hasAmount
            ? BigDecimal::of((string) $promotion['bill_discount_amount'])
            : $total->multipliedBy((string) $promotion['bill_discount_percent'])->dividedBy(100, 2, RoundingMode::HALF_UP);
        if ($discount->isNegative()) {
            throw new InvalidArgumentException('ส่วนลดท้ายบิลต้องไม่ติดลบ');
        }

        return $discount->toScale(2, RoundingMode::HALF_UP);
    }

    private static function lines(array $eligibleLines): array
    {
        if ($eligibleLines === []) {
            throw new InvalidArgumentException('Promotion ท้ายบิลต้องมีรายการที่ใช้ส่วนลดได้');
        }

        $numbers = [];
        foreach ($eligibleLines as $line) {
            if (! isset($line['line_number'], $line['amount']) || ! is_int($line['line_number'])) {
                throw new InvalidArgumentException('รายการส่วนลดท้ายบิลไม่สมบูรณ์');
            }
            $amount = BigDecimal::of((string) $line['amount'])->toScale(2, RoundingMode::HALF_UP);
            if (! $amount->isPositive() || isset($numbers[$line['line_number']])) {
                throw new InvalidArgumentException('รายการส่วนลดท้ายบิลต้องมียอดมากกว่าศูนย์และเลขบรรทัดไม่ซ้ำ');
            }
            $numbers[$line['line_number']] = true;
            $lines[] = ['line_number' => $line['line_number'], 'amount' => $amount];
        }

        return $lines;
    }
}
