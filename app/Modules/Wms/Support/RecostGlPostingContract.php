<?php

namespace App\Modules\Wms\Support;

use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * Read-only contract for the future RECOST -> GL boundary.
 * It deliberately returns a posting plan only; it never creates a Journal.
 */
final class RecostGlPostingContract
{
    public static function preflight(array $input): array
    {
        foreach (['warehouse_id', 'item_id', 'recost_request_id', 'parent_allocation_id'] as $key) {
            if (! isset($input[$key]) || (int) $input[$key] < 1) {
                self::fail($key, 'ต้องมี source identity ที่เป็นค่าบวก');
            }
        }
        if (! isset($input['revision']) || (int) $input['revision'] < 0) {
            self::fail('revision', 'Revision ต้องเป็นศูนย์หรือค่าบวก');
        }
        $delta = BigDecimal::of((string) ($input['delta_value'] ?? '0'));
        if ($delta->isZero()) {
            self::fail('delta_value', 'Recost ที่เป็นศูนย์ไม่ต้องสร้าง GL delta');
        }
        if (($input['status'] ?? 'PENDING') !== 'PENDING' || ($input['journal_entry_id'] ?? null) !== null) {
            self::fail('status', 'Recost ต้องยังไม่ Post และห้ามสร้าง Journal ซ้ำ');
        }
        if (($input['period_open'] ?? false) !== true) {
            self::fail('period', 'งวดบัญชีต้องเปิดก่อน Post Recost');
        }
        if (($input['reconciliation_ready'] ?? false) !== true) {
            self::fail('reconciliation', 'ต้องผ่าน reconciliation ก่อน Post Recost');
        }
        if (($input['source_type'] ?? '') !== 'WMS_RECOST') {
            self::fail('source_type', 'Source identity ของ Recost ไม่ถูกต้อง');
        }

        $mapping = $delta->isPositive() ? 'INVENTORY_RECOST_GAIN' : 'INVENTORY_RECOST_LOSS';
        $identity = implode(':', ['wms-recost', $input['warehouse_id'], $input['item_id'], $input['recost_request_id'], $input['parent_allocation_id'], $input['revision']]);
        $hash = hash('sha256', json_encode([
            'identity' => $identity, 'delta_value' => $delta->__toString(),
            'business_date' => (string) ($input['business_date'] ?? ''), 'mapping' => $mapping,
        ], JSON_THROW_ON_ERROR));

        return [
            'event_code' => 'inventory.recost', 'source_type' => 'WMS_RECOST',
            'source_identity' => $identity, 'idempotency_hash' => $hash,
            'mapping_keys' => ['INVENTORY_DEFAULT', $mapping],
            'delta_direction' => $delta->isPositive() ? 'INCREASE_INVENTORY' : 'DECREASE_INVENTORY',
            'delta_value' => $delta->abs()->__toString(), 'period_lock_required' => true,
            'reconciliation_required' => true, 'journal_linkage' => 'allocation.journal_entry_id',
            'reversal' => 'immutable_reversal_with_reversal_of', 'creates_journal' => false,
        ];
    }

    private static function fail(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }
}
