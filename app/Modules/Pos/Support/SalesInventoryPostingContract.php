<?php

namespace App\Modules\Pos\Support;

use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * Read-only boundary for a future POS sale -> WMS ISSUE/COGS flow.
 * Service lines remain valid POS lines but are explicitly not inventory lines.
 */
final class SalesInventoryPostingContract
{
    /**
     * @return array{eligible:bool,reason:string,stock_quantity?:string,sale_quantity?:string,sale_uom_id?:int,stock_uom_id?:int,factor?:string,conversion_snapshot?:array<string,mixed>}
     */
    public static function preview(array $line): array
    {
        if (empty($line['item_id'])) {
            return ['eligible' => false, 'reason' => 'SERVICE_LINE'];
        }
        foreach (['item_id', 'uom_id', 'stock_uom_id', 'warehouse_id'] as $field) {
            if ((int) ($line[$field] ?? 0) < 1) {
                throw ValidationException::withMessages([$field => 'Inventory sale ต้องมี Item, Warehouse และ UOM ครบ']);
            }
        }
        $quantity = self::positiveDecimal($line['quantity'] ?? null, 'quantity');
        $factor = self::positiveDecimal($line['factor'] ?? $line['uom_factor'] ?? null, 'factor');
        $businessDate = trim((string) ($line['business_date'] ?? ''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
            throw ValidationException::withMessages(['business_date' => 'Inventory sale ต้องมีวันที่เอกสารรูปแบบ Y-m-d']);
        }
        $snapshot = is_array($line['conversion_snapshot'] ?? null) ? $line['conversion_snapshot'] : [];
        if ($snapshot !== [] && ((int) ($snapshot['purchase_uom_id'] ?? $line['uom_id']) !== (int) $line['uom_id'] || (int) ($snapshot['stock_uom_id'] ?? $line['stock_uom_id']) !== (int) $line['stock_uom_id'] || (string) ($snapshot['factor'] ?? '') !== $factor->toScale(8)->__toString() || (string) ($snapshot['business_date'] ?? '') !== $businessDate)) {
            throw ValidationException::withMessages(['conversion_snapshot' => 'Snapshot ของ UOM ต้องตรงกับ Sales line และวันที่เอกสาร']);
        }
        $snapshot = array_replace([
            'purchase_uom_id' => (int) $line['uom_id'],
            'stock_uom_id' => (int) $line['stock_uom_id'],
            'factor' => $factor->toScale(8)->__toString(),
            'business_date' => $businessDate,
        ], $snapshot);

        return [
            'eligible' => true,
            'reason' => 'INVENTORY_LINE',
            'sale_quantity' => $quantity->toScale(8)->__toString(),
            'sale_uom_id' => (int) $line['uom_id'],
            'stock_uom_id' => (int) $line['stock_uom_id'],
            'factor' => $factor->toScale(8)->__toString(),
            'stock_quantity' => $quantity->multipliedBy($factor)->toScale(8)->__toString(),
            'conversion_snapshot' => $snapshot,
        ];
    }

    private static function positiveDecimal(mixed $value, string $field): BigDecimal
    {
        try {
            $decimal = BigDecimal::of((string) $value);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'ต้องเป็นเลขทศนิยมมากกว่าศูนย์']);
        }
        if ($decimal->isLessThanOrEqualTo(BigDecimal::zero())) {
            throw ValidationException::withMessages([$field => 'ต้องมากกว่าศูนย์']);
        }

        return $decimal;
    }
}
