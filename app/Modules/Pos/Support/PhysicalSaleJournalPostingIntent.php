<?php

namespace App\Modules\Pos\Support;

use App\Modules\Accounting\Support\JournalBalance;
use Illuminate\Validation\ValidationException;

/**
 * Builds the deterministic AR/revenue journal for a physical HS/IV sale.
 * This is intentionally side-effect free; posting must be coordinated with
 * Stock ISSUE, cost allocation and both journal linkages by a later service.
 */
final class PhysicalSaleJournalPostingIntent
{
    /**
     * @return array{source_type:string,source_id:string,source_reference:string,event_code:string,entry_date:string,document_date:string,description:string,lines:list<array<string,mixed>>}
     */
    public static function build(array $sale): array
    {
        $type = strtoupper(trim((string) ($sale['document_type'] ?? '')));
        if (! in_array($type, ['HS', 'IV'], true)) {
            throw ValidationException::withMessages(['document_type' => 'เอกสารขายสินค้าต้องเป็น HS หรือ IV']);
        }

        $saleId = (int) ($sale['id'] ?? 0);
        $warehouseId = (int) ($sale['warehouse_id'] ?? 0);
        $partyId = (int) ($sale['party_id'] ?? 0);
        $arAccountId = (int) ($sale['ar_account_id'] ?? 0);
        $documentNumber = trim((string) ($sale['document_number'] ?? ''));
        $documentDate = trim((string) ($sale['document_date'] ?? ''));
        $date = trim((string) ($sale['business_date'] ?? $sale['posting_date'] ?? $documentDate));
        if ($saleId < 1 || $warehouseId < 1 || $partyId < 1 || $arAccountId < 1 || $documentNumber === '') {
            throw ValidationException::withMessages(['sale' => 'ข้อมูล HS/IV สำหรับลงบัญชีไม่ครบถ้วน']);
        }
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $documentDate)) {
            throw ValidationException::withMessages(['posting_date' => 'วันที่ Post ต้องอยู่ในรูปแบบ Y-m-d']);
        }
        if (JournalBalance::decimal($sale['tax_amount'] ?? '0') !== '0.00') {
            throw ValidationException::withMessages(['tax_amount' => 'HS/IV inventory posting รองรับเฉพาะ NONE VAT ใน MVP']);
        }

        $lines = $sale['lines'] ?? null;
        if (! is_array($lines) || $lines === []) {
            throw ValidationException::withMessages(['lines' => 'HS/IV ต้องมีรายการสินค้า']);
        }

        $groups = [];
        foreach (array_values($lines) as $index => $line) {
            $lineNumber = (int) ($line['line_number'] ?? 0);
            $accountId = (int) ($line['revenue_account_id'] ?? $line['sales_account_id'] ?? 0);
            if ($lineNumber < 1 || $accountId < 1) {
                throw ValidationException::withMessages(["lines.$index" => 'รายการต้องมี line number และบัญชีรายได้']);
            }
            $amount = JournalBalance::decimal($line['line_total'] ?? '0.00');
            if ($amount === '0.00' || str_starts_with($amount, '-')) {
                throw ValidationException::withMessages(["lines.$index.line_total" => 'ยอดรายการต้องมากกว่าศูนย์']);
            }
            $groups[$accountId] = JournalBalance::add($groups[$accountId] ?? '0.00', $amount);
        }
        if ($groups === []) {
            throw ValidationException::withMessages(['lines' => 'ไม่มียอดรายการสำหรับลงบัญชี']);
        }
        ksort($groups, SORT_NUMERIC);
        $total = array_reduce($groups, static fn (string $sum, string $amount): string => JournalBalance::add($sum, $amount), '0.00');

        $journalLines = [[
            'account_id' => $arAccountId,
            'subledger_type' => 'CUSTOMER',
            'subledger_id' => (string) $partyId,
            'description' => $documentNumber,
            'debit' => $total,
            'credit' => '0.00',
            'tax_base' => $total,
            'tax_amount' => '0.00',
            'tax_point_date' => $date,
        ]];
        foreach ($groups as $accountId => $amount) {
            $journalLines[] = [
                'account_id' => (int) $accountId,
                'description' => $documentNumber,
                'debit' => '0.00',
                'credit' => $amount,
                'tax_base' => $amount,
                'tax_amount' => '0.00',
                'tax_point_date' => $date,
            ];
        }

        return [
            'source_type' => 'POS',
            'source_id' => (string) $saleId,
            'source_reference' => $documentNumber,
            'event_code' => 'sales_invoice',
            'entry_date' => $date,
            'document_date' => $documentDate,
            'description' => "Post {$type} {$documentNumber}",
            'lines' => $journalLines,
        ];
    }
}
