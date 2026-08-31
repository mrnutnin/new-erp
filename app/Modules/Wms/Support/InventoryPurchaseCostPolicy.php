<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/** Pure, decimal-safe receipt cost policy for NONE_VAT Inventory Purchase. */
final class InventoryPurchaseCostPolicy
{
    public const VERSION = 'inventory-purchase-cost-v1';

    public static function resolve(string $grossAmount, string $baseQuantity, string $taxTreatment = 'NONE_VAT'): array
    {
        if (strtoupper($taxTreatment) !== 'NONE_VAT') {
            throw ValidationException::withMessages(['tax_treatment' => 'Inventory Purchase cost policy รองรับเฉพาะ NONE VAT']);
        }

        $gross = BigDecimal::of($grossAmount)->toScale(2, RoundingMode::UNNECESSARY);
        $quantity = BigDecimal::of($baseQuantity)->toScale(8, RoundingMode::UNNECESSARY);
        if ($gross->isNegative() || $gross->isZero()) {
            throw ValidationException::withMessages(['gross_amount' => 'ยอดสินค้าเพื่อคำนวณต้นทุนต้องมากกว่า 0']);
        }
        if ($quantity->isNegative() || $quantity->isZero()) {
            throw ValidationException::withMessages(['base_quantity' => 'Base quantity เพื่อคำนวณต้นทุนต้องมากกว่า 0']);
        }

        $unitCost = $gross->dividedBy($quantity, 8, RoundingMode::HALF_UP);
        $recalculated = $unitCost->multipliedBy($quantity)->toScale(2, RoundingMode::HALF_UP);
        if ($recalculated->compareTo($gross) !== 0) {
            throw ValidationException::withMessages(['gross_amount' => 'ยอด gross ไม่สอดคล้องกับ unit cost และ base quantity หลังปัดเศษ']);
        }

        return [
            'unit_cost' => $unitCost->toScale(8, RoundingMode::HALF_UP)->__toString(),
            'value' => $gross->__toString(),
            'policy_version' => self::VERSION,
            'tax_treatment' => 'NONE_VAT',
        ];
    }
}
