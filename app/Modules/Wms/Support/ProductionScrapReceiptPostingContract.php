<?php

namespace App\Modules\Wms\Support;

use App\Modules\Accounting\Support\PostingEvent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

final class ProductionScrapReceiptPostingContract
{
    public const CONTEXT = 'PRODUCTION_SCRAP_RECEIPT';
    public const EVENT = 'production.scrap_receipt';
    public const SOURCE_TYPE = 'WMS_PRODUCTION_SCRAP_RECEIPT';
    public const VERSION = 1;

    /** @return array<string,mixed> */
    public static function plan(array $document): array
    {
        self::assertHeader($document);
        $lines = $document['lines'] ?? null;
        if (! is_array($lines) || $lines === []) {
            self::fail('lines', 'ใบรับเศษผลิตต้องมีรายการอย่างน้อยหนึ่งรายการ');
        }

        $movements = [];
        $allocations = [];
        $totalValue = BigDecimal::zero();
        foreach (array_values($lines) as $index => $line) {
            $lineId = self::positiveInt($line['id'] ?? null, "lines.{$index}.id");
            $quantity = self::positiveDecimal($line['quantity'] ?? null, "lines.{$index}.quantity");
            $value = self::positiveDecimal($line['value'] ?? null, "lines.{$index}.value");
            $unitCost = $value->dividedBy($quantity, 8, RoundingMode::HALF_UP);
            $identity = 'production-scrap-receipt:'.(int) $document['id'].':line:'.$lineId;
            $movement = StockMovementContract::normalize([
                'warehouse_id' => (int) $document['warehouse_id'],
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
                    'document_context' => self::CONTEXT,
                    'production_order_id' => isset($document['production_order_id']) ? (int) $document['production_order_id'] : null,
                    'source_issue_id' => isset($document['source_issue_id']) ? (int) $document['source_issue_id'] : null,
                    'scrap_receipt_line_id' => $lineId,
                    'source_material_line_id' => isset($line['source_material_line_id']) ? (int) $line['source_material_line_id'] : null,
                    'unit_cost' => $unitCost->toScale(8, RoundingMode::HALF_UP)->__toString(),
                    'receipt_value' => $value->toScale(8)->__toString(),
                    'unit_cost_trusted' => true,
                ],
            ]);
            $movements[] = $movement;
            $allocations[] = [
                'idempotency_key' => 'allocation:'.$identity,
                'movement_idempotency_key' => $identity,
                'quantity' => $quantity->toScale(8)->__toString(),
                'value' => $value->toScale(8)->__toString(),
                'unit_cost' => $unitCost->toScale(8, RoundingMode::HALF_UP)->__toString(),
                'allocation_type' => 'RECEIPT',
                'cost_status' => 'FINAL',
            ];
            $totalValue = $totalValue->plus($value);
        }

        $availableWip = self::decimal($document['available_wip_value'] ?? null, 'available_wip_value');
        if ($totalValue->isGreaterThan($availableWip)) {
            self::fail('value', 'มูลค่ารับเศษสะสมต้องไม่เกิน Available WIP ของใบสั่งผลิต');
        }
        $amount = $totalValue->toScale(8, RoundingMode::HALF_UP)->__toString();
        $payload = [
            'contract_version' => self::VERSION,
            'event_code' => self::EVENT,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => (string) $document['id'],
            'source_reference' => trim((string) $document['document_number']),
            'production_order_id' => isset($document['production_order_id']) ? (int) $document['production_order_id'] : null,
            'source_issue_id' => isset($document['source_issue_id']) ? (int) $document['source_issue_id'] : null,
            'warehouse_id' => (int) $document['warehouse_id'],
            'business_date' => (string) $document['document_date'],
            'movement_intents' => $movements,
            'allocation_intents' => $allocations,
            'journal_intent' => ['debit_role' => 'SCRAP_INVENTORY', 'credit_role' => 'WIP', 'amount' => $amount],
            'journal_roles' => PostingEvent::roles(self::EVENT),
        ];

        return [...$payload, 'posting_hash' => hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
    }

    private static function assertHeader(array $document): void
    {
        foreach (['id', 'warehouse_id'] as $field) {
            self::positiveInt($document[$field] ?? null, $field);
        }
        $productionOrderId = filter_var($document['production_order_id'] ?? null, FILTER_VALIDATE_INT);
        $sourceIssueId = filter_var($document['source_issue_id'] ?? null, FILTER_VALIDATE_INT);
        if ((! $productionOrderId || $productionOrderId < 1) && (! $sourceIssueId || $sourceIssueId < 1)) {
            self::fail('source', 'ใบรับเศษผลิตต้องอ้างอิง Production Order หรือใบเบิกวัตถุดิบผลิต');
        }
        if (($document['document_context'] ?? null) !== self::CONTEXT) {
            self::fail('document_context', 'เอกสารนี้ไม่ใช่ใบรับเศษผลิต');
        }
        if (($document['status'] ?? null) !== 'APPROVED') {
            self::fail('status', 'เตรียม Post ได้เฉพาะใบรับเศษผลิตที่อนุมัติแล้ว');
        }
        if (trim((string) ($document['document_number'] ?? '')) === '') {
            self::fail('document_number', 'ใบรับเศษผลิตต้องมีเลขที่เอกสาร');
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($document['document_date'] ?? ''))) {
            self::fail('document_date', 'วันที่ใบรับเศษผลิตต้องอยู่ในรูปแบบ Y-m-d');
        }
        if (mb_strlen(trim((string) ($document['reason'] ?? ''))) < 10) {
            self::fail('reason', 'ใบรับเศษผลิตต้องมีเหตุผลอย่างน้อย 10 ตัวอักษร');
        }
    }

    private static function positiveInt(mixed $value, string $field): int
    {
        if (! filter_var($value, FILTER_VALIDATE_INT) || (int) $value < 1) {
            self::fail($field, 'ต้องเป็นรหัสจำนวนเต็มที่มากกว่า 0');
        }

        return (int) $value;
    }

    private static function positiveDecimal(mixed $value, string $field): BigDecimal
    {
        $decimal = self::decimal($value, $field);
        if ($decimal->isLessThanOrEqualTo(BigDecimal::zero())) {
            self::fail($field, 'ต้องมากกว่าศูนย์');
        }

        return $decimal;
    }

    private static function decimal(mixed $value, string $field): BigDecimal
    {
        try {
            $decimal = BigDecimal::of((string) $value)->toScale(8, RoundingMode::UNNECESSARY);
        } catch (\Throwable) {
            self::fail($field, 'ต้องเป็นเลขทศนิยมที่ถูกต้อง');
        }
        if ($decimal->isNegative()) {
            self::fail($field, 'ต้องไม่ติดลบ');
        }

        return $decimal;
    }

    private static function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
