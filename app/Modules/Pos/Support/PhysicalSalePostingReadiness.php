<?php

namespace App\Modules\Pos\Support;

use App\Modules\Accounting\Support\JournalBalance;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/**
 * Read-only gate used immediately before a future HS/IV posting transaction.
 * It deliberately does not resolve accounts or mutate stock/GL records.
 */
final class PhysicalSalePostingReadiness
{
    /**
     * @return array{sale_id:int,document_number:string,document_type:string,source_type:string,line_count:int,total_amount:string}
     */
    public static function assertReady(array $sale): array
    {
        $status = strtoupper(trim((string) ($sale['status'] ?? '')));
        if ($status !== 'DRAFT') {
            throw ValidationException::withMessages(['status' => 'HS/IV ต้องอยู่สถานะร่างก่อนลงบัญชี']);
        }
        if (($sale['journal_entry_id'] ?? null) !== null || ($sale['cogs_journal_entry_id'] ?? null) !== null) {
            throw ValidationException::withMessages(['status' => 'เอกสารนี้มี Journal แล้ว ไม่สามารถลงบัญชีซ้ำ']);
        }

        $saleId = (int) ($sale['id'] ?? 0);
        $number = trim((string) ($sale['document_number'] ?? ''));
        $type = strtoupper(trim((string) ($sale['document_type'] ?? '')));
        $sourceType = strtoupper(trim((string) ($sale['source_type'] ?? '')));
        $sourceId = (int) ($sale['source_id'] ?? 0);
        $warehouseId = (int) ($sale['warehouse_id'] ?? 0);
        if ($saleId < 1 || $number === '' || $warehouseId < 1 || $sourceId < 1) {
            throw ValidationException::withMessages(['sale' => 'HS/IV ต้องมีเลขที่ เอกสารต้นทาง และคลังสินค้า']);
        }
        if (! in_array($type, ['HS', 'IV'], true) || ! in_array($sourceType, ['SALES_ORDER', 'PRODUCTION_RECEIPT'], true)) {
            throw ValidationException::withMessages(['source_type' => 'แหล่งที่มา HS/IV ไม่ถูกต้อง']);
        }

        $documentDate = trim((string) ($sale['document_date'] ?? ''));
        $postingDate = trim((string) ($sale['posting_date'] ?? $documentDate));
        foreach (['document_date' => $documentDate, 'posting_date' => $postingDate] as $field => $date) {
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                throw ValidationException::withMessages([$field => 'วันที่ต้องอยู่ในรูปแบบ Y-m-d']);
            }
        }
        if ($postingDate < $documentDate) {
            throw ValidationException::withMessages(['posting_date' => 'วันที่ Post ต้องไม่ก่อนวันที่เอกสาร']);
        }
        $lines = $sale['lines'] ?? null;
        if (! is_array($lines) || $lines === []) {
            throw ValidationException::withMessages(['lines' => 'HS/IV ต้องมีรายการสินค้าอย่างน้อย 1 รายการ']);
        }
        $lineNumbers = [];
        $lineTotal = '0.00';
        foreach (array_values($lines) as $index => $line) {
            $lineNumber = (int) ($line['line_number'] ?? 0);
            if ($lineNumber < 1 || isset($lineNumbers[$lineNumber])) {
                throw ValidationException::withMessages(["lines.$index.line_number" => 'ลำดับรายการสินค้าต้องไม่ซ้ำกัน']);
            }
            $lineNumbers[$lineNumber] = true;
            if ((int) ($line['item_id'] ?? 0) < 1 || (int) ($line['stock_uom_id'] ?? 0) < 1) {
                throw ValidationException::withMessages(["lines.$index" => 'รายการต้องมีสินค้าและหน่วย Stock']);
            }
            $stockQuantity = BigDecimal::of((string) ($line['stock_quantity'] ?? '0'));
            if ($stockQuantity->isLessThanOrEqualTo(BigDecimal::zero())) {
                throw ValidationException::withMessages(["lines.$index.stock_quantity" => 'จำนวน Stock ต้องมากกว่าศูนย์']);
            }
            $amount = JournalBalance::decimal($line['line_total'] ?? '0');
            if (str_starts_with($amount, '-')) {
                throw ValidationException::withMessages(["lines.$index.line_total" => 'ยอดรายการต้องไม่ติดลบ']);
            }
            $lineTotal = JournalBalance::add($lineTotal, $amount);
        }
        $total = JournalBalance::decimal($sale['total_amount'] ?? $lineTotal);
        if ($total !== $lineTotal) {
            throw ValidationException::withMessages(['total_amount' => 'ยอดรวมเอกสารไม่เท่ากับยอดรายการ']);
        }

        return [
            'sale_id' => $saleId,
            'document_number' => $number,
            'document_type' => $type,
            'source_type' => $sourceType,
            'line_count' => count($lines),
            'total_amount' => $total,
        ];
    }
}
