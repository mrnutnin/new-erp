<?php

namespace App\Modules\Purchasing\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class LandedCostAllocationCalculator
{
    /**
     * @param array<int, array{id:int, value:string, quantity?:string, weight?:string}> $targets
     * @param array<int, array{id:int, amount:string}> $charges
     * @return array{total:string, allocations:array<int, array{charge_id:int,target_id:int,basis:string,ratio:string,amount:string}>}
     */
    public static function preview(array $targets, array $charges, string $basis = 'VALUE'): array
    {
        $basis = strtoupper(trim($basis));
        if (! in_array($basis, ['VALUE', 'QUANTITY', 'WEIGHT'], true)) {
            throw ValidationException::withMessages(['allocation_basis' => 'รองรับ allocation basis เฉพาะ VALUE, QUANTITY หรือ WEIGHT']);
        }
        if ($targets === [] || $charges === []) {
            throw ValidationException::withMessages(['allocation' => 'ต้องมีรายการสินค้าและค่าใช้จ่ายอย่างน้อยหนึ่งรายการ']);
        }

        $basisKey = strtolower($basis);
        $normalized = collect($targets)->map(fn (array $target): array => [
            'id' => (int) ($target['id'] ?? 0),
            'basis' => BigDecimal::of((string) ($target[$basisKey] ?? '0')),
        ])->values();
        if ($normalized->contains(fn (array $target): bool => $target['id'] < 1 || $target['basis']->isLessThanOrEqualTo(0))) {
            throw ValidationException::withMessages(['targets' => 'Target ทุกตัวต้องมีรหัสและฐานจัดสรรมากกว่าศูนย์']);
        }

        $totalBasis = $normalized->reduce(fn (BigDecimal $total, array $target): BigDecimal => $total->plus($target['basis']), BigDecimal::zero());
        $result = [];
        $total = BigDecimal::zero();
        foreach ($charges as $charge) {
            $chargeId = (int) ($charge['id'] ?? 0);
            $amount = BigDecimal::of((string) ($charge['amount'] ?? '0'));
            if ($chargeId < 1 || $amount->isLessThanOrEqualTo(0)) {
                throw ValidationException::withMessages(['charges' => 'ค่าใช้จ่ายทุกตัวต้องมีรหัสและจำนวนเงินมากกว่าศูนย์']);
            }
            $posted = BigDecimal::zero();
            foreach ($normalized as $index => $target) {
                $allocation = $index === $normalized->count() - 1
                    ? $amount->minus($posted)
                    : $amount->multipliedBy($target['basis'])->dividedBy($totalBasis, 8, RoundingMode::HALF_UP);
                $posted = $posted->plus($allocation);
                $total = $total->plus($allocation);
                $result[] = [
                    'charge_id' => $chargeId,
                    'target_id' => $target['id'],
                    'basis' => $target['basis']->toScale(8, RoundingMode::HALF_UP)->__toString(),
                    'ratio' => $target['basis']->dividedBy($totalBasis, 12, RoundingMode::HALF_UP)->__toString(),
                    'amount' => $allocation->toScale(8, RoundingMode::HALF_UP)->__toString(),
                ];
            }
        }

        return ['total' => $total->toScale(8, RoundingMode::HALF_UP)->__toString(), 'allocations' => $result];
    }
}
