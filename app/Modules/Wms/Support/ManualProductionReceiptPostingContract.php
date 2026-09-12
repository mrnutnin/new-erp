<?php

namespace App\Modules\Wms\Support;

use App\Modules\Accounting\Support\PostingEvent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class ManualProductionReceiptPostingContract
{
    public const VERSION = 1;
    public const SOURCE_TYPE = 'WMS_PRODUCTION_RECEIPT';

    /**
     * Build a deterministic, write-free posting plan. The future workflow
     * must execute every intent in one outer transaction.
     *
     * @return array<string, mixed>
     */
    public static function plan(array $document): array
    {
        self::assertHeader($document);
        $lines = $document['lines'] ?? [];
        if (! is_array($lines) || $lines === []) {
            self::fail('lines', 'Finished Receipt ต้องมีรายการอย่างน้อยหนึ่งรายการ');
        }

        $roundedLines = self::roundedLines($lines);
        $movements = [];
        $allocations = [];
        foreach ($lines as $index => $line) {
            $lineId = self::positiveInt($line['id'] ?? null, "lines.{$index}.id");
            $quantity = self::positiveDecimal($line['quantity'] ?? null, "lines.{$index}.quantity");
            $value = self::positiveDecimal($line['value'] ?? null, "lines.{$index}.value");
            $unitCost = $value->dividedBy($quantity, 8, RoundingMode::HALF_UP);
            $receiptValue = $roundedLines[$index]['receipt_value'];
            $identity = 'production-receipt:'.(int) $document['id'].':line:'.$lineId;

            $movements[] = StockMovementContract::normalize([
                'warehouse_id' => self::positiveInt($document['warehouse_id'] ?? null, 'warehouse_id'),
                'item_id' => self::positiveInt($line['item_id'] ?? null, "lines.{$index}.item_id"),
                'uom_id' => self::positiveInt($line['uom_id'] ?? null, "lines.{$index}.uom_id"),
                'movement_type' => 'RECEIPT',
                'direction' => 'IN',
                'quantity' => $quantity->toScale(8)->__toString(),
                'base_quantity' => $quantity->toScale(8)->__toString(),
                'business_date' => (string) $document['document_date'],
                'source_type' => self::SOURCE_TYPE,
                'source_id' => (string) $document['id'],
                'source_reference' => (string) $document['document_number'],
                'idempotency_key' => $identity,
                'metadata' => [
                    'contract_version' => self::VERSION,
                    'document_context' => ManualProductionReceiptContract::CONTEXT,
                    'line_id' => $lineId,
                    'unit_cost' => $unitCost->toScale(8, RoundingMode::HALF_UP)->__toString(),
                    'receipt_value' => $receiptValue,
                    'unit_cost_trusted' => true,
                ],
            ]);
            $allocations[] = [
                'idempotency_key' => 'allocation:'.$identity,
                'movement_idempotency_key' => $identity,
                'quantity' => $quantity->toScale(8)->__toString(),
                'value' => $receiptValue,
                'unit_cost' => $unitCost->toScale(8, RoundingMode::HALF_UP)->__toString(),
                'allocation_type' => 'RECEIPT',
                'cost_status' => 'FINAL',
            ];
        }

        $payload = [
            'contract_version' => self::VERSION,
            'event_code' => 'production.finished_receipt',
            'source_type' => self::SOURCE_TYPE,
            'source_id' => (string) $document['id'],
            'source_reference' => (string) $document['document_number'],
            'warehouse_id' => (int) $document['warehouse_id'],
            'business_date' => (string) $document['document_date'],
            'reason' => trim((string) $document['reason']),
            'movement_intents' => $movements,
            'allocation_intents' => $allocations,
            'journal_roles' => PostingEvent::roles('production.finished_receipt'),
        ];

        return [...$payload, 'posting_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
    }

    private static function assertHeader(array $document): void
    {
        if (($document['document_context'] ?? null) !== ManualProductionReceiptContract::CONTEXT) {
            self::fail('document_context', 'Posting plan นี้ใช้ได้กับ Finished Receipt เท่านั้น');
        }
        if (($document['status'] ?? null) !== 'APPROVED') {
            self::fail('status', 'Finished Receipt ต้อง Approved ก่อนสร้าง Posting plan');
        }
        foreach (['id', 'warehouse_id'] as $field) {
            self::positiveInt($document[$field] ?? null, $field);
        }
        foreach (['document_number', 'reason'] as $field) {
            if (trim((string) ($document[$field] ?? '')) === '') {
                self::fail($field, 'ต้องระบุ '.$field);
            }
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($document['document_date'] ?? ''))) {
            self::fail('document_date', 'วันที่ต้องเป็นรูปแบบ Y-m-d');
        }
    }

    /** Keep the exact document total while assigning rounding residual to the last line. */
    private static function roundedLines(array $lines): array
    {
        $result = [];
        $documentTotal = BigDecimal::zero();
        $roundedTotal = BigDecimal::zero();
        foreach ($lines as $index => $line) {
            $quantity = self::positiveDecimal($line['quantity'] ?? null, "lines.{$index}.quantity");
            $value = self::positiveDecimal($line['value'] ?? null, "lines.{$index}.value");
            $unitCost = $value->dividedBy($quantity, 8, RoundingMode::HALF_UP);
            $roundedValue = $quantity->multipliedBy($unitCost)->toScale(8, RoundingMode::HALF_UP);
            $documentTotal = $documentTotal->plus($value);
            $roundedTotal = $roundedTotal->plus($roundedValue);
            $result[$index] = ['receipt_value' => $roundedValue];
        }
        $last = array_key_last($result);
        $residual = $documentTotal->toScale(8, RoundingMode::UNNECESSARY)->minus($roundedTotal->toScale(8, RoundingMode::HALF_UP));
        $result[$last]['receipt_value'] = $result[$last]['receipt_value']->plus($residual)->toScale(8, RoundingMode::UNNECESSARY);
        if ($result[$last]['receipt_value']->isLessThanOrEqualTo(BigDecimal::zero())) {
            self::fail('lines', 'ไม่สามารถกระจาย residual ของต้นทุนรับผลิตได้อย่างปลอดภัย');
        }
        foreach ($result as $index => $row) {
            $result[$index]['receipt_value'] = $row['receipt_value']->toScale(8, RoundingMode::UNNECESSARY)->__toString();
        }
        return $result;
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (! filter_var($value, FILTER_VALIDATE_INT) || (int) $value < 1) {
            self::fail($field, 'ต้องเป็นจำนวนเต็มที่มากกว่า 0');
        }

        return (int) $value;
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
