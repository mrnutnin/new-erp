<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class StockMovementContract
{
    public static function normalize(array $attributes): array
    {
        $attributes['movement_type'] = strtoupper(trim((string) ($attributes['movement_type'] ?? '')));
        $attributes['direction'] = strtoupper(trim((string) ($attributes['direction'] ?? '')));
        $attributes['status'] = strtoupper(trim((string) ($attributes['status'] ?? 'DRAFT')));
        foreach (['warehouse_id', 'item_id', 'uom_id'] as $field) {
            if (! filter_var($attributes[$field] ?? null, FILTER_VALIDATE_INT) || $attributes[$field] < 1) {
                self::fail($field, 'ต้องเป็นรหัสจำนวนเต็มที่มากกว่า 0');
            }
        }
        if (! in_array($attributes['movement_type'], ['RECEIPT', 'ISSUE', 'TRANSFER', 'ADJUSTMENT', 'COUNT'], true)) {
            self::fail('movement_type', 'ชนิด Movement ไม่ถูกต้อง');
        }
        if (! in_array($attributes['direction'], ['IN', 'OUT'], true)) {
            self::fail('direction', 'ทิศทางไม่ถูกต้อง');
        }
        if (! in_array($attributes['status'], ['DRAFT', 'POSTED'], true)) {
            self::fail('status', 'สถานะไม่ถูกต้อง');
        }
        foreach (['quantity', 'base_quantity'] as $field) {
            if (! is_string($attributes[$field] ?? null) || ! preg_match('/^\d+(?:\.\d{1,8})?$/', $attributes[$field]) || BigDecimal::of($attributes[$field])->isZero()) {
                self::fail($field, 'ต้องเป็นจำนวนบวกและมีทศนิยมไม่เกิน 8 ตำแหน่ง');
            }
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($attributes['business_date'] ?? ''))) {
            self::fail('business_date', 'วันที่ต้องเป็นรูปแบบ Y-m-d');
        }
        if (! is_string($attributes['idempotency_key'] ?? null) || $attributes['idempotency_key'] === '' || strlen($attributes['idempotency_key']) > 160) {
            self::fail('idempotency_key', 'ต้องระบุ idempotency key ไม่เกิน 160 ตัวอักษร');
        }

        if ($attributes['movement_type'] === 'TRANSFER') {
            $transferKey = trim((string) ($attributes['transfer_key'] ?? ''));
            if ($transferKey === '' || strlen($transferKey) > 100) {
                self::fail('transfer_key', 'การโอนต้องมี transfer key ไม่เกิน 100 ตัวอักษร');
            }
            $attributes['transfer_key'] = $transferKey;
        }
        $quantity = BigDecimal::of((string) $attributes['quantity'])->toScale(8, RoundingMode::UNNECESSARY);
        $baseQuantity = BigDecimal::of((string) $attributes['base_quantity'])->toScale(8, RoundingMode::UNNECESSARY);

        return [...$attributes, 'quantity' => $quantity->__toString(), 'base_quantity' => $baseQuantity->__toString()];
    }

    private static function fail(string $field, string $message): never
    {
        throw new InvalidArgumentException("{$field}: {$message}");
    }
}
