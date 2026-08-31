<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Resolves a purchase quantity into the stock UOM without reading a mutable
 * current conversion inside a posting path. The returned snapshot is the
 * contract future Receipt posting must persist with its movement/allocation.
 * This class deliberately does not create stock, cost, or accounting rows.
 */
final class GoodsReceiptConversionContract
{
    /**
     * @param  array{purchase_qty:string|int|float,purchase_uom_id:int,stock_uom_id:int,business_date:string,total_cost:string|int|float,conversion_candidates?:array<int,array<string,mixed>>}  $input
     * @return array{purchase_quantity:string,stock_quantity:string,total_cost:string,stock_unit_cost:string,rounding_delta:string,snapshot:array<string,mixed>}
     */
    public static function resolve(array $input): array
    {
        $purchaseQuantity = self::decimal($input['purchase_qty'] ?? null, 'purchase_qty');
        $totalCost = self::decimal($input['total_cost'] ?? null, 'total_cost', false);
        $purchaseUom = self::positiveInt($input['purchase_uom_id'] ?? null, 'purchase_uom_id');
        $stockUom = self::positiveInt($input['stock_uom_id'] ?? null, 'stock_uom_id');
        $date = (string) ($input['business_date'] ?? '');
        $parsedDate = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        if (! $parsedDate || $parsedDate->format('Y-m-d') !== $date) {
            self::fail('business_date', 'วันที่รับต้องเป็นรูปแบบ Y-m-d');
        }

        $factor = BigDecimal::one();
        $conversion = null;
        if ($purchaseUom !== $stockUom) {
            $candidates = array_values(array_filter($input['conversion_candidates'] ?? [], static function (mixed $candidate) use ($purchaseUom, $stockUom): bool {
                return is_array($candidate)
                    && (int) ($candidate['from_uom_id'] ?? 0) === $purchaseUom
                    && (int) ($candidate['to_uom_id'] ?? 0) === $stockUom;
            }));
            $valid = array_values(array_filter($candidates, static function (array $candidate) use ($date): bool {
                if (($candidate['is_active'] ?? true) !== true) {
                    return false;
                }
                $from = $candidate['effective_from'] ?? null;
                $to = $candidate['effective_to'] ?? null;

                return is_string($from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)
                    && $from <= $date
                    && ($to === null || (is_string($to) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $date <= $to));
            }));
            if (count($valid) !== 1) {
                self::fail('conversion', count($valid) > 1
                    ? 'พบ UOM conversion ที่ active และมีผลในวันที่รับมากกว่าหนึ่งรายการ'
                    : 'ไม่พบ UOM conversion ที่ active และมีผลในวันที่รับ');
            }
            $conversion = $valid[0];
            $factor = self::decimal($conversion['factor'] ?? null, 'conversion.factor');
        }

        $stockQuantity = $purchaseQuantity->multipliedBy($factor)->toScale(8, RoundingMode::HALF_UP);
        if ($stockQuantity->isZero()) {
            self::fail('stock_quantity', 'จำนวน Stock หลังแปลงต้องมากกว่าศูนย์');
        }
        $stockUnitCost = $totalCost->dividedBy($stockQuantity, 8, RoundingMode::HALF_UP)->toScale(8, RoundingMode::HALF_UP);
        $distributedCost = $stockUnitCost->multipliedBy($stockQuantity)->toScale(8, RoundingMode::HALF_UP);
        $roundingDelta = $totalCost->minus($distributedCost)->toScale(8, RoundingMode::HALF_UP);

        return [
            'purchase_quantity' => $purchaseQuantity->toScale(8, RoundingMode::HALF_UP)->__toString(),
            'stock_quantity' => $stockQuantity->__toString(),
            'total_cost' => $totalCost->toScale(8, RoundingMode::HALF_UP)->__toString(),
            'stock_unit_cost' => $stockUnitCost->__toString(),
            'rounding_delta' => $roundingDelta->__toString(),
            'snapshot' => [
                'purchase_uom_id' => $purchaseUom,
                'stock_uom_id' => $stockUom,
                'factor' => $factor->toScale(8, RoundingMode::HALF_UP)->__toString(),
                'conversion_id' => $conversion['id'] ?? null,
                'effective_from' => $conversion['effective_from'] ?? $date,
                'effective_to' => $conversion['effective_to'] ?? null,
                'business_date' => $date,
            ],
        ];
    }

    private static function decimal(mixed $value, string $field, bool $mustBePositive = true): BigDecimal
    {
        $string = (string) $value;
        if (! preg_match('/^\d+(?:\.\d{1,8})?$/', $string)) {
            self::fail($field, 'ต้องเป็นเลขทศนิยมไม่เกิน 8 ตำแหน่ง');
        }
        $decimal = BigDecimal::of($string);
        if (($mustBePositive && $decimal->isLessThanOrEqualTo(BigDecimal::zero())) || (! $mustBePositive && $decimal->isNegative())) {
            self::fail($field, $mustBePositive ? 'ต้องมากกว่าศูนย์' : 'ต้องไม่ติดลบ');
        }

        return $decimal;
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (! filter_var($value, FILTER_VALIDATE_INT) || (int) $value < 1) {
            self::fail($field, 'ต้องเป็นรหัสจำนวนเต็มที่มากกว่า 0');
        }

        return (int) $value;
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
