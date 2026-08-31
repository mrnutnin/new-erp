<?php

namespace App\Modules\Pos\Support;

use App\Modules\Accounting\Models\TaxCode;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class PhysicalSaleWithholdingSnapshot
{
    /** @return array{withholding_tax_code_id:?int,withholding_rate:string,withholding_base:string,withholding_amount:string} */
    public static function build(?TaxCode $tax, mixed $base, mixed $maximumBase): array
    {
        $base = BigDecimal::of((string) ($base ?? '0'))->toScale(2, RoundingMode::HALF_UP);
        $maximumBase = BigDecimal::of((string) ($maximumBase ?? '0'))->toScale(2, RoundingMode::HALF_UP);
        if (! $tax) {
            if ($base->isPositive()) {
                throw ValidationException::withMessages(['withholding_tax_code_id' => 'ต้องเลือก Tax Code หัก ณ ที่จ่าย']);
            }

            return ['withholding_tax_code_id' => null, 'withholding_rate' => '0.0000', 'withholding_base' => '0.00', 'withholding_amount' => '0.00'];
        }
        if (! $tax->is_active || $tax->kind !== 'WHT') {
            throw ValidationException::withMessages(['withholding_tax_code_id' => 'Tax Code หัก ณ ที่จ่ายต้องเปิดใช้งาน']);
        }
        if ($base->isNegative() || $base->isGreaterThan($maximumBase)) {
            throw ValidationException::withMessages(['withholding_base' => 'ฐานหัก ณ ที่จ่ายต้องไม่เกินยอดก่อนภาษีของเอกสาร']);
        }

        $rate = BigDecimal::of((string) $tax->rate)->toScale(4, RoundingMode::HALF_UP);
        $amount = $base->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP);

        return [
            'withholding_tax_code_id' => (int) $tax->id,
            'withholding_rate' => $rate->__toString(),
            'withholding_base' => $base->__toString(),
            'withholding_amount' => $amount->toScale(2, RoundingMode::HALF_UP)->__toString(),
        ];
    }

    /** @return array{withholding_tax_code_id:?int,withholding_rate:string,withholding_base:string,withholding_amount:string} */
    public static function assertStored(mixed $id, mixed $rate, mixed $base, mixed $amount, mixed $maximumBase): array
    {
        $id = (int) $id ?: null;
        $rate = BigDecimal::of((string) ($rate ?? '0'))->toScale(4, RoundingMode::HALF_UP);
        $base = BigDecimal::of((string) ($base ?? '0'))->toScale(2, RoundingMode::HALF_UP);
        $amount = BigDecimal::of((string) ($amount ?? '0'))->toScale(2, RoundingMode::HALF_UP);
        $maximumBase = BigDecimal::of((string) ($maximumBase ?? '0'))->toScale(2, RoundingMode::HALF_UP);
        if (! $id) {
            if (! $rate->isZero() || ! $base->isZero() || ! $amount->isZero()) {
                throw ValidationException::withMessages(['withholding_tax_code_id' => 'WHT snapshot ต้องมี Tax Code']);
            }

            return ['withholding_tax_code_id' => null, 'withholding_rate' => '0.0000', 'withholding_base' => '0.00', 'withholding_amount' => '0.00'];
        }
        $expected = $base->multipliedBy($rate)->dividedBy(100, 2, RoundingMode::HALF_UP)->toScale(2, RoundingMode::HALF_UP);
        if ($rate->isNegative() || $base->isNegative() || $amount->isNegative() || $base->isGreaterThan($maximumBase) || ! $amount->isEqualTo($expected)) {
            throw ValidationException::withMessages(['withholding_amount' => 'WHT snapshot ของ HS/IV ไม่สมบูรณ์']);
        }

        return ['withholding_tax_code_id' => $id, 'withholding_rate' => $rate->__toString(), 'withholding_base' => $base->__toString(), 'withholding_amount' => $amount->__toString()];
    }
}
