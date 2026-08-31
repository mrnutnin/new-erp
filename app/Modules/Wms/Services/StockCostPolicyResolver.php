<?php

namespace App\Modules\Wms\Services;

use App\Modules\Settings\Services\GlobalSettings;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class StockCostPolicyResolver
{
    public function __construct(private readonly GlobalSettings $settings) {}

    /**
     * Resolve an issue that may exceed on-hand. The caller still owns movement posting.
     * A shortage is represented as a PENDING provisional cost, never as a fake FINAL layer.
     */
    public function resolveIssue(
        string $availableQuantity,
        string $issueQuantity,
        ?string $currentAverageCost = null,
        ?string $lastKnownCost = null,
        ?string $standardCost = null,
    ): array {
        $available = $this->decimal($availableQuantity, 'available_quantity', true);
        $issue = $this->decimal($issueQuantity, 'issue_quantity');
        if ($issue->isZero()) {
            throw ValidationException::withMessages(['issue_quantity' => 'จำนวนจ่ายต้องมากกว่า 0']);
        }

        $method = $this->settings->value('inventory_costing_method');
        if (! in_array($method, ['AVG', 'FIFO'], true)) {
            throw ValidationException::withMessages(['inventory_costing_method' => 'ต้องตั้งค่า Inventory costing method ก่อนจ่ายสินค้า']);
        }

        $shortfall = $issue->isGreaterThan($available) ? $issue->minus($available) : BigDecimal::zero();
        if ($shortfall->isZero()) {
            return [
                'status' => 'FINAL',
                'method' => $method,
                'shortfall_quantity' => self::out($shortfall),
                'unit_cost' => null,
                'value' => '0.00000000',
            ];
        }

        if ($this->settings->value('allow_negative_stock') !== true) {
            throw ValidationException::withMessages(['available_quantity' => 'สินค้าไม่เพียงพอและไม่อนุญาตให้สต็อกติดลบ']);
        }

        $negativeMethod = $this->settings->value('negative_stock_cost_method');
        $cost = match ($negativeMethod) {
            'CURRENT_AVERAGE' => $currentAverageCost,
            'LAST_KNOWN' => $lastKnownCost,
            'STANDARD' => $standardCost,
            default => null,
        };
        if (! in_array($negativeMethod, ['CURRENT_AVERAGE', 'LAST_KNOWN', 'STANDARD'], true)) {
            throw ValidationException::withMessages(['negative_stock_cost_method' => 'ต้องตั้งค่าวิธีต้นทุนชั่วคราวก่อนอนุญาตสต็อกติดลบ']);
        }
        if ($cost === null) {
            throw ValidationException::withMessages(['unit_cost' => 'ไม่พบต้นทุนสำหรับสร้าง provisional negative layer']);
        }
        $cost = $this->decimal($cost, 'unit_cost');

        return [
            'status' => 'PENDING',
            'method' => $method,
            'negative_cost_method' => $negativeMethod,
            'shortfall_quantity' => self::out($shortfall),
            'unit_cost' => self::out($cost),
            'value' => self::out($shortfall->multipliedBy($cost)),
        ];
    }

    private function decimal(string $value, string $field, bool $signed = false): BigDecimal
    {
        $pattern = $signed ? '/^-?\d+(?:\.\d{1,8})?$/' : '/^\d+(?:\.\d{1,8})?$/';
        if (! preg_match($pattern, $value)) {
            throw ValidationException::withMessages([$field => $signed ? 'ค่าต้องเป็นเลขทศนิยม สูงสุด 8 ตำแหน่ง' : 'ค่าต้องเป็นเลขทศนิยมไม่ติดลบ สูงสุด 8 ตำแหน่ง']);
        }

        return BigDecimal::of($value)->toScale(8, RoundingMode::UNNECESSARY);
    }

    private static function out(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }
}
