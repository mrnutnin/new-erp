<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * Read-only readiness contract for count/stock adjustment -> GL.
 * It deliberately creates neither movement, allocation nor Journal rows.
 */
final class InventoryAdjustmentPostingContract
{
    /** @return array{event_code:string,direction:string,movement_direction:string,mapping_key:string,posting_hash:string,creates_journal:false} */
    public static function preview(array $input): array
    {
        $direction = strtoupper(trim((string) ($input['direction'] ?? '')));
        if (! in_array($direction, ['GAIN', 'LOSS'], true)) {
            self::fail('direction', 'Adjustment ต้องระบุทิศทาง GAIN หรือ LOSS');
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if (mb_strlen($reason) < 10) {
            self::fail('reason', 'Adjustment ต้องมีเหตุผลอย่างน้อย 10 ตัวอักษร');
        }
        foreach (['warehouse_id', 'item_id', 'uom_id'] as $field) {
            if (! filter_var($input[$field] ?? null, FILTER_VALIDATE_INT) || (int) $input[$field] < 1) {
                self::fail($field, 'ต้องเป็นรหัสจำนวนเต็มที่มากกว่า 0');
            }
        }
        $date = trim((string) ($input['business_date'] ?? ''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            self::fail('business_date', 'วันที่ Adjustment ต้องเป็นรูปแบบ Y-m-d');
        }
        $quantity = self::positiveDecimal($input['quantity'] ?? null, 'quantity');
        $value = self::positiveDecimal($input['value'] ?? null, 'value');
        foreach (['source_type', 'source_id', 'source_reference', 'idempotency_key'] as $field) {
            if (trim((string) ($input[$field] ?? '')) === '') {
                self::fail($field, 'Adjustment ต้องมี source identity และ idempotency key ครบ');
            }
        }
        foreach (['approved', 'period_open', 'reconciled'] as $gate) {
            if (($input[$gate] ?? false) !== true) {
                self::fail($gate, 'Adjustment ยังไม่ผ่าน approval/period/reconciliation gate');
            }
        }

        $payload = [
            'event_code' => 'inventory.adjustment', 'direction' => $direction,
            'warehouse_id' => (int) $input['warehouse_id'], 'item_id' => (int) $input['item_id'],
            'uom_id' => (int) $input['uom_id'], 'business_date' => $date,
            'quantity' => $quantity->toScale(8)->__toString(), 'value' => $value->toScale(8)->__toString(),
            'reason' => $reason, 'source_type' => trim((string) $input['source_type']),
            'source_id' => trim((string) $input['source_id']), 'source_reference' => trim((string) $input['source_reference']),
            'idempotency_key' => trim((string) $input['idempotency_key']),
        ];
        $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if (($input['existing_posting_hash'] ?? null) !== null && ! hash_equals((string) $input['existing_posting_hash'], $hash)) {
            self::fail('idempotency_key', 'Adjustment idempotency เดิมมี payload ไม่ตรงกัน ห้าม retry ทับยอด');
        }

        return [
            'event_code' => 'inventory.adjustment', 'direction' => $direction,
            'movement_direction' => $direction === 'GAIN' ? 'IN' : 'OUT',
            'mapping_key' => $direction === 'GAIN' ? 'INVENTORY_ADJUSTMENT_GAIN' : 'INVENTORY_ADJUSTMENT_LOSS',
            'posting_hash' => $hash, 'creates_journal' => false,
        ];
    }

    private static function positiveDecimal(mixed $value, string $field): BigDecimal
    {
        try {
            $decimal = BigDecimal::of((string) $value);
        } catch (\Throwable) {
            self::fail($field, 'ต้องเป็นเลขทศนิยมมากกว่าศูนย์');
        }
        if ($decimal->isLessThanOrEqualTo(BigDecimal::zero())) {
            self::fail($field, 'ต้องมากกว่าศูนย์');
        }

        return $decimal;
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
