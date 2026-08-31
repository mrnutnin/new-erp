<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

final class TransferContract
{
    public static function normalizeHeader(array $attributes): array
    {
        $source = self::positiveInt($attributes['source_warehouse_id'] ?? null, 'source_warehouse_id');
        $destination = self::positiveInt($attributes['destination_warehouse_id'] ?? null, 'destination_warehouse_id');
        if ($source === $destination) {
            self::fail('destination_warehouse_id', 'คลังต้นทางและปลายทางต้องไม่ใช่คลังเดียวกัน');
        }
        $date = (string) ($attributes['document_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            self::fail('document_date', 'วันที่ต้องเป็นรูปแบบ Y-m-d');
        }
        $key = trim((string) ($attributes['idempotency_key'] ?? ''));
        if ($key === '' || strlen($key) > 160) {
            self::fail('idempotency_key', 'ต้องระบุ idempotency key ไม่เกิน 160 ตัวอักษร');
        }

        return [...$attributes, 'source_warehouse_id' => $source, 'destination_warehouse_id' => $destination, 'document_date' => $date, 'idempotency_key' => $key];
    }

    public static function normalizeQuantity(mixed $value, string $field = 'quantity'): string
    {
        $value = (string) $value;
        if (! preg_match('/^\d+(?:\.\d{1,8})?$/', $value) || BigDecimal::of($value)->isZero()) {
            self::fail($field, 'ต้องเป็นจำนวนบวกและมีทศนิยมไม่เกิน 8 ตำแหน่ง');
        }

        return BigDecimal::of($value)->toScale(8, RoundingMode::UNNECESSARY)->__toString();
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
        throw new InvalidArgumentException("{$field}: {$message}");
    }
}
