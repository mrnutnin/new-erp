<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class CostingCalculator
{
    public static function average(string $openingQuantity, string $openingCost, string $receiptQuantity, string $receiptUnitCost): array
    {
        // A receipt may close a provisional negative-stock issue. In that
        // case the opening quantity/value is legitimately negative; reject
        // negative costs, but preserve the signed quantity until the receipt
        // brings the projection back to zero or positive stock.
        $opening = self::signedDecimal($openingQuantity);
        $openingValue = $opening->multipliedBy(self::decimal($openingCost));
        $receipt = self::decimal($receiptQuantity);
        $totalQuantity = $opening->plus($receipt);
        if ($totalQuantity->isZero()) {
            return ['quantity' => '0.00000000', 'unit_cost' => '0.00000000', 'value' => '0.00000000'];
        }
        $value = $openingValue->plus($receipt->multipliedBy(self::decimal($receiptUnitCost)));

        return ['quantity' => self::out($totalQuantity), 'unit_cost' => self::out($value->dividedBy($totalQuantity, 8, RoundingMode::HALF_UP)), 'value' => self::out($value)];
    }

    public static function averageIssue(string $openingQuantity, string $openingCost, string $issueQuantity): array
    {
        $opening = self::decimal($openingQuantity);
        $issue = self::decimal($issueQuantity);
        if ($issue->isGreaterThan($opening)) {
            throw new InvalidArgumentException('AVG stock มีจำนวนไม่พอสำหรับการจ่าย');
        }
        $quantity = $opening->minus($issue);
        $value = $opening->multipliedBy(self::decimal($openingCost))->minus($issue->multipliedBy(self::decimal($openingCost)));

        return ['quantity' => self::out($quantity), 'unit_cost' => $quantity->isZero() ? '0.00000000' : self::out($value->dividedBy($quantity, 8, RoundingMode::HALF_UP)), 'value' => self::out($value)];
    }

    /** @param array<int,array{quantity:string,unit_cost:string}> $layers */
    public static function fifoIssue(array $layers, string $issueQuantity): array
    {
        $remaining = self::decimal($issueQuantity);
        if ($remaining->isZero()) {
            throw new InvalidArgumentException('จำนวนจ่ายต้องมากกว่า 0');
        }
        $allocations = [];
        foreach ($layers as $index => $layer) {
            if ($remaining->isZero()) {
                break;
            }
            $available = self::decimal((string) ($layer['quantity'] ?? '0'));
            $take = $remaining->isLessThan($available) ? $remaining : $available;
            if ($take->isPositive()) {
                $unitCost = self::decimal((string) ($layer['unit_cost'] ?? '0'));
                $allocations[] = ['layer' => $index, 'quantity' => self::out($take), 'unit_cost' => self::out($unitCost), 'value' => self::out($take->multipliedBy($unitCost))];
                $remaining = $remaining->minus($take);
            }
        }
        if ($remaining->isPositive()) {
            throw new InvalidArgumentException('FIFO layers มีจำนวนไม่พอสำหรับการจ่าย');
        }

        return $allocations;
    }

    private static function decimal(string $value): BigDecimal
    {
        if (! preg_match('/^\d+(?:\.\d{1,8})?$/', $value)) {
            throw new InvalidArgumentException('Costing value ต้องเป็นเลขทศนิยมไม่ติดลบ');
        }

        return BigDecimal::of($value)->toScale(8, RoundingMode::UNNECESSARY);
    }

    private static function signedDecimal(string $value): BigDecimal
    {
        if (! preg_match('/^-?\d+(?:\.\d{1,8})?$/', $value)) {
            throw new InvalidArgumentException('Costing quantity ต้องเป็นเลขทศนิยม');
        }

        return BigDecimal::of($value)->toScale(8, RoundingMode::UNNECESSARY);
    }

    private static function out(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }
}
