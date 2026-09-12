<?php

namespace App\Modules\Wms\Support;

use Illuminate\Validation\ValidationException;

final class ManualProductionReceiptContract
{
    public const CONTEXT = 'PRODUCTION_RECEIPT';

    /**
     * Enforce the source contract before a finished-goods receipt can enter
     * the document workflow. Posting remains a separate, closed gate.
     */
    public static function assert(array $values): void
    {
        if (($values['document_context'] ?? null) !== self::CONTEXT) {
            throw ValidationException::withMessages(['document_context' => 'เอกสารนี้ไม่ใช่ Manual Finished Goods Receipt']);
        }
        if (($values['direction'] ?? null) !== 'GAIN') {
            throw ValidationException::withMessages(['direction' => 'Finished Goods Receipt ต้องเป็นรายการรับเข้าเท่านั้น']);
        }
        if (empty($values['lines']) || ! is_array($values['lines'])) {
            throw ValidationException::withMessages(['lines' => 'Finished Goods Receipt ต้องมีรายการสินค้า']);
        }

        foreach ($values['lines'] as $index => $line) {
            if ((float) ($line['quantity'] ?? 0) <= 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity" => 'จำนวนรับผลิตต้องมากกว่า 0']);
            }
            if ((float) ($line['value'] ?? 0) <= 0) {
                throw ValidationException::withMessages(["lines.{$index}.value" => 'ต้นทุนรวมรับผลิตต้องมากกว่า 0']);
            }
        }
    }

    public static function preflight(array $mappingReadiness): array
    {
        $blockers = $mappingReadiness['blockers'] ?? [];

        if (($mappingReadiness['event_code'] ?? null) !== 'production.finished_receipt') {
            $blockers[] = ['code' => 'INVALID_EVENT', 'message' => 'Production Finished Receipt ใช้ event contract ไม่ถูกต้อง'];
        }
        if (($mappingReadiness['ready'] ?? false) !== true) {
            $blockers[] = ['code' => 'PRODUCTION_MAPPING_NOT_READY', 'message' => 'Production account mapping ยังไม่พร้อม'];
        }

        return ['ready' => $blockers === [], 'blockers' => array_values($blockers)];
    }
}
