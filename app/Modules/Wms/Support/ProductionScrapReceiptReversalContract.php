<?php

namespace App\Modules\Wms\Support;

use Illuminate\Validation\ValidationException;

final class ProductionScrapReceiptReversalContract
{
    /** @return array<string,mixed> */
    public static function plan(array $document, string $reversalDate, string $reason): array
    {
        if (($document['document_context'] ?? null) !== ProductionScrapReceiptPostingContract::CONTEXT) {
            self::fail('document_context', 'เอกสารนี้ไม่ใช่ใบรับเศษผลิต');
        }
        if (($document['status'] ?? null) !== 'POSTED' || ($document['reversal_status'] ?? 'NONE') === 'REVERSED') {
            self::fail('status', 'กลับรายการได้เฉพาะใบรับเศษผลิตที่ Post และยังไม่ถูกกลับรายการ');
        }
        $documentId = self::positiveInt($document['id'] ?? null, 'id');
        $revision = self::positiveInt(((int) ($document['reversal_revision'] ?? 0)) + 1, 'reversal_revision');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $reversalDate)) {
            self::fail('reversal_date', 'วันที่กลับรายการต้องอยู่ในรูปแบบ Y-m-d');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            self::fail('reason', 'ต้องระบุเหตุผลการกลับรายการอย่างน้อย 10 ตัวอักษร');
        }
        $lines = $document['lines'] ?? null;
        if (! is_array($lines) || $lines === []) {
            self::fail('lines', 'ใบรับเศษผลิตต้องมีรายการ Posted');
        }

        $identity = "reversal:production-scrap-receipt:{$documentId}:revision:{$revision}";
        $movements = [];
        foreach (array_values($lines) as $index => $line) {
            if (($line['status'] ?? null) !== 'POSTED') {
                self::fail("lines.{$index}.status", 'รายการรับเศษต้อง Post แล้ว');
            }
            $movements[] = [
                'source_stock_movement_id' => self::positiveInt($line['stock_movement_id'] ?? null, "lines.{$index}.stock_movement_id"),
                'source_cost_allocation_id' => self::positiveInt($line['cost_allocation_id'] ?? null, "lines.{$index}.cost_allocation_id"),
                'business_date' => $reversalDate,
                'idempotency_key' => $identity.':line:'.self::positiveInt($line['id'] ?? null, "lines.{$index}.id").':movement',
            ];
        }

        $payload = [
            'event_code' => ProductionScrapReceiptPostingContract::EVENT,
            'source_type' => ProductionScrapReceiptPostingContract::SOURCE_TYPE,
            'source_id' => $identity,
            'original_document_id' => $documentId,
            'original_journal_entry_id' => self::positiveInt($document['journal_entry_id'] ?? null, 'journal_entry_id'),
            'reversal_revision' => $revision,
            'reversal_date' => $reversalDate,
            'reason' => $reason,
            'movement_reversals' => $movements,
            'journal_reversal' => 'ORIGINAL_JOURNAL',
        ];

        return [...$payload, 'reversal_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
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
