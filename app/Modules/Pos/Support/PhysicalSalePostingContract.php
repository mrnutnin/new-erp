<?php

namespace App\Modules\Pos\Support;

use Illuminate\Validation\ValidationException;

/**
 * Validation-only boundary for physical HS/IV sales.
 * Posting is intentionally owned by a later service that coordinates WMS and GL.
 */
final class PhysicalSalePostingContract
{
    /**
     * @return array{document_type:string,source_type:string,source_id:int,warehouse_id:int,business_date:string,lines:list<array<string,mixed>>}
     */
    public static function preview(array $document): array
    {
        $type = strtoupper(trim((string) ($document['document_type'] ?? '')));
        if (! in_array($type, ['HS', 'IV'], true)) {
            throw ValidationException::withMessages(['document_type' => 'เอกสารขายสินค้าต้องเป็น HS หรือ IV']);
        }

        $sourceType = strtoupper(trim((string) ($document['source_type'] ?? '')));
        if (! in_array($sourceType, ['SALES_ORDER', 'PRODUCTION_RECEIPT'], true)) {
            throw ValidationException::withMessages(['source_type' => 'HS/IV ต้องอ้างอิง Sales Order หรือใบรับผลิต']);
        }
        $sourceId = (int) ($document['source_id'] ?? 0);
        $warehouseId = (int) ($document['warehouse_id'] ?? 0);
        if ($sourceId < 1 || $warehouseId < 1) {
            throw ValidationException::withMessages(['source_id' => 'ต้องระบุเอกสารต้นทางและคลังสินค้า']);
        }

        $businessDate = trim((string) ($document['business_date'] ?? ''));
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
            throw ValidationException::withMessages(['business_date' => 'วันที่ขายต้องอยู่ในรูปแบบ Y-m-d']);
        }

        $lines = $document['lines'] ?? null;
        if (! is_array($lines) || $lines === []) {
            throw ValidationException::withMessages(['lines' => 'HS/IV ต้องมีรายการสินค้าอย่างน้อย 1 รายการ']);
        }

        $seen = [];
        $physicalLines = [];
        foreach (array_values($lines) as $index => $line) {
            $lineNumber = (int) ($line['line_number'] ?? ($index + 1));
            if ($lineNumber < 1 || isset($seen[$lineNumber])) {
                throw ValidationException::withMessages(["lines.$index.line_number" => 'ลำดับรายการสินค้าต้องไม่ซ้ำกัน']);
            }
            $seen[$lineNumber] = true;

            $preview = SalesInventoryPostingContract::preview(array_merge($line, [
                'warehouse_id' => $warehouseId,
                'business_date' => $businessDate,
            ]));
            if (! $preview['eligible']) {
                throw ValidationException::withMessages(["lines.$index.item_id" => 'HS/IV ไม่รองรับรายการบริการ ต้องเป็นสินค้าเท่านั้น']);
            }

            $physicalLines[] = [
                'line_number' => $lineNumber,
                'item_id' => (int) $line['item_id'],
                'sale_quantity' => $preview['sale_quantity'],
                'sale_uom_id' => $preview['sale_uom_id'],
                'stock_uom_id' => $preview['stock_uom_id'],
                'factor' => $preview['factor'],
                'stock_quantity' => $preview['stock_quantity'],
                'conversion_snapshot' => $preview['conversion_snapshot'],
            ];
        }

        usort($physicalLines, static fn (array $a, array $b): int => $a['line_number'] <=> $b['line_number']);

        return [
            'document_type' => $type,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'warehouse_id' => $warehouseId,
            'business_date' => $businessDate,
            'lines' => $physicalLines,
        ];
    }
}
