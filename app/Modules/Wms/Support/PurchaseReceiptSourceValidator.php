<?php

namespace App\Modules\Wms\Support;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Wms\Models\StockMovement;
use Illuminate\Validation\ValidationException;

/**
 * Read-only guard for a receipt that refers to a posted purchase invoice.
 * Purchase invoice posting owns Dr Inventory / Cr AP; a receipt must reuse
 * that Journal and must never create a second accounting entry.
 */
final class PurchaseReceiptSourceValidator
{
    public function resolve(StockMovement $movement): PurchaseDocument
    {
        if (strtoupper((string) $movement->source_type) !== 'PURCHASING' || ! ctype_digit((string) $movement->source_id)) {
            throw ValidationException::withMessages(['source' => 'Receipt ต้องอ้าง Purchase Invoice ด้วย source type PURCHASING และ source id ที่ถูกต้อง']);
        }

        $document = PurchaseDocument::query()
            ->select(['id', 'warehouse_id', 'document_type', 'document_number', 'document_date', 'status', 'journal_entry_id'])
            ->with('journalEntry:id,warehouse_id,entry_number,source_type,source_event,source_id,source_reference,status')
            ->whereKey((int) $movement->source_id)
            ->where('warehouse_id', (int) $movement->warehouse_id)
            ->where('document_type', 'INVOICE')
            ->first();
        if (! $document) {
            throw ValidationException::withMessages(['source' => 'ไม่พบ Purchase Invoice ต้นทางของ Receipt']);
        }

        return $document;
    }

    /**
     * $sourceLine is intentionally explicit because current purchase lines do
     * not yet carry item/UOM linkage. Omitting it must remain a blocker.
     */
    public function assertReady(
        PurchaseDocument $document,
        JournalEntry $journal,
        StockMovement $movement,
        CostAllocation $allocation,
        ?array $sourceLine = null,
    ): void {
        $this->assertDocumentReady($document, $journal);
        if ((string) $movement->source_type !== 'PURCHASING' || (string) $movement->source_id !== (string) $document->id || (string) $movement->source_reference !== (string) $document->document_number) {
            throw ValidationException::withMessages(['source' => 'Receipt source identity ไม่ตรงกับ Purchase Invoice']);
        }
        if ((int) $movement->warehouse_id !== (int) $document->warehouse_id || (int) $journal->warehouse_id !== (int) $document->warehouse_id) {
            throw ValidationException::withMessages(['warehouse_id' => 'Purchase Invoice, Journal และ Receipt ต้องอยู่คลังเดียวกัน']);
        }
        if ((string) $journal->status !== 'POSTED' || (int) $journal->id !== (int) $document->journal_entry_id
            || (string) $journal->source_type !== 'PURCHASING' || (string) $journal->source_event !== 'supplier_invoice.inventory'
            || (string) $journal->source_id !== (string) $document->id || (string) $journal->source_reference !== (string) $document->document_number) {
            throw ValidationException::withMessages(['journal_entry_id' => 'Journal เดิมของ Purchase Invoice ไม่ใช่ Inventory invoice ที่ POSTED']);
        }
        if ($movement->business_date?->lt($document->document_date)) {
            throw ValidationException::withMessages(['business_date' => 'วันที่ Receipt ต้องไม่ก่อนวันที่ Purchase Invoice']);
        }
        if ($allocation->journal_entry_id !== null || (string) $allocation->status !== 'PENDING') {
            throw ValidationException::withMessages(['allocation' => 'Cost allocation นี้ถูกผูก Journal หรือไม่อยู่ในสถานะรอ Post แล้ว']);
        }
        InventorySourceContract::assertCompatible($movement, $allocation, 'inventory.receipt');

        if (! $sourceLine || ! isset($sourceLine['item_id'], $sourceLine['uom_id'])) {
            throw ValidationException::withMessages(['source_line' => 'Purchase Invoice ยังไม่มี linkage ระบุ item/UOM สำหรับ Receipt; ห้ามเดาจากบรรทัดบัญชี']);
        }
        if ((int) $sourceLine['item_id'] !== (int) $movement->item_id || (int) $sourceLine['uom_id'] !== (int) $movement->uom_id) {
            throw ValidationException::withMessages(['source_line' => 'Item/UOM ของ Receipt ไม่ตรงกับ Purchase Invoice line ต้นทาง']);
        }
    }

    public function assertDocumentReady(PurchaseDocument $document, ?JournalEntry $journal): void
    {
        if ((string) $document->document_type !== 'INVOICE' || (string) $document->status !== 'POSTED' || ! $document->journal_entry_id || ! $journal) {
            throw ValidationException::withMessages(['source' => 'Purchase Invoice ต้องเป็น POSTED และมี Journal เดิมก่อนรับสินค้า']);
        }
        if ((string) $journal->status !== 'POSTED' || (int) $journal->id !== (int) $document->journal_entry_id
            || (string) $journal->source_type !== 'PURCHASING' || (string) $journal->source_event !== 'supplier_invoice.inventory'
            || (string) $journal->source_id !== (string) $document->id || (string) $journal->source_reference !== (string) $document->document_number) {
            throw ValidationException::withMessages(['journal_entry_id' => 'Journal เดิมของ Purchase Invoice ไม่ใช่ Inventory invoice ที่ POSTED']);
        }
    }
}
