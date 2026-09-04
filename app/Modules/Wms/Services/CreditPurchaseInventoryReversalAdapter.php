<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostAllocationJournalLine;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\CreditPurchaseInventoryReversalContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Full-line Credit Purchase -> GR inventory reversal. Closed by the caller's
 * feature gate; source documents, movements and allocations remain immutable.
 */
final class CreditPurchaseInventoryReversalAdapter
{
    public function __construct(
        private readonly StockMovementService $movements,
        private readonly InventoryReconciliationService $reconciliation,
        private readonly InventoryCostAllocationService $allocations,
    ) {}

    public function reverse(PurchaseDocument $credit, string $date, string $reason, User $actor, bool $featureEnabled = false): PurchaseDocument
    {
        if (! $featureEnabled) {
            throw ValidationException::withMessages(['reversal' => 'Credit Purchase → GR Inventory reversal ยังไม่เปิดใช้งาน']);
        }

        return DB::transaction(function () use ($credit, $date, $reason, $actor): PurchaseDocument {
            $credit = PurchaseDocument::query()->with('lines.receiptAllocations')->lockForUpdate()->findOrFail($credit->id);
            if ($credit->inventory_reversal_movement_id || $credit->inventory_reversal_allocation_id) {
                if (! $credit->inventory_reversal_movement_id || ! $credit->inventory_reversal_allocation_id || $credit->reversal_reason !== $reason) {
                    throw ValidationException::withMessages(['reversal' => 'Credit Purchase ถูกกลับรายการด้วย identity อื่นแล้ว']);
                }

                return $credit->fresh();
            }
            if ($credit->status !== 'POSTED' || $credit->document_type !== 'CREDIT_NOTE' || $credit->credit_note_mode !== 'RETURN' || ! $credit->original_document_id || ! $credit->journal_entry_id) {
                throw ValidationException::withMessages(['source' => 'ต้องใช้ Credit Purchase ที่ Post แล้วและอ้าง Invoice ต้นทาง']);
            }
            $original = PurchaseDocument::query()->with('lines.receiptAllocations')->lockForUpdate()->findOrFail($credit->original_document_id);
            $creditReceiptAllocationCount = $credit->lines->flatMap->receiptAllocations->count();
            $originalReceiptAllocationCount = $original->lines->flatMap->receiptAllocations->count();
            if ($credit->lines->count() !== 1 || $original->lines->count() !== 1
                || $creditReceiptAllocationCount !== 1 || $originalReceiptAllocationCount !== 1) {
                throw ValidationException::withMessages([
                    'reversal' => 'การกลับรายการ Credit Purchase ที่อ้างอิงหลายรายการสินค้า/หลาย GR ยังไม่เปิดใช้ใน MVP; กรุณาแยกใบลดหนี้เป็นรายการละ GR ก่อนกลับรายการ',
                ]);
            }
            $creditLine = $credit->lines->sole();
            $originalLine = $original->lines->sole();
            $creditAlloc = $creditLine->receiptAllocations->sole();
            $originalAlloc = $originalLine->receiptAllocations->sole();
            $movement = StockMovement::query()->where('source_type', 'PURCHASING')->where('source_id', (string) $original->id)->where('status', 'POSTED')->lockForUpdate()->get();
            if ($movement->count() !== 1) {
                throw ValidationException::withMessages(['movement' => 'Invoice ต้นทางต้องมี Inventory Movement Posted เพียงหนึ่งรายการ']);
            }
            $movement = $movement->sole();
            $sourceAllocation = CostAllocation::query()->where('stock_movement_id', $movement->id)->where('status', 'POSTED')->where('cost_status', 'FINAL')->lockForUpdate()->get();
            if ($sourceAllocation->count() !== 1) {
                throw ValidationException::withMessages(['allocation' => 'Invoice ต้นทางต้องมี Cost Allocation FINAL เพียงหนึ่งรายการ']);
            }
            $sourceAllocation = $sourceAllocation->sole();
            $originalLink = CostAllocationJournalLine::query()->where('allocation_id', $sourceAllocation->id)->lockForUpdate()->first();
            $originalJournalLine = $originalLink ? JournalEntryLine::query()->whereKey($originalLink->journal_entry_line_id)->first() : null;
            $creditJournal = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($credit->journal_entry_id);
            if ($creditJournal->status !== 'POSTED'
                || $creditJournal->source_type !== 'PURCHASING'
                || $creditJournal->source_event !== 'purchase_credit_note'
                || (string) $creditJournal->source_id !== (string) $credit->id
                || (int) $creditJournal->warehouse_id !== (int) $credit->warehouse_id) {
                throw ValidationException::withMessages(['journal_entry_id' => 'Credit Purchase Journal ต้องเป็น Posted purchase_credit_note ของ Warehouse เดียวกัน']);
            }
            $creditLineJournal = $creditJournal->lines->filter(fn (JournalEntryLine $line): bool => $originalJournalLine
                && (int) $line->account_id === (int) $originalJournalLine->account_id
                && (string) $line->debit === (string) $originalJournalLine->credit
                && (string) $line->credit === (string) $originalJournalLine->debit)->values();
            if ($creditLineJournal->count() !== 1) {
                throw ValidationException::withMessages(['journal_line' => 'Credit Purchase ต้องมี Journal line สินค้ากลับด้านตรงกับ allocation ต้นทาง']);
            }
            $plan = CreditPurchaseInventoryReversalContract::plan([
                'credit_document_id' => $credit->id, 'original_document_id' => $original->id, 'credit_journal_id' => $creditJournal->id,
                'movement_id' => $movement->id, 'allocation_id' => $sourceAllocation->id, 'credit_journal_line_id' => $creditLineJournal->sole()->id,
                'credit_document_status' => $credit->status, 'credit_document_type' => $credit->document_type, 'original_document_status' => $original->status,
                'movement_status' => $movement->status, 'allocation_status' => $sourceAllocation->status, 'allocation_cost_status' => $sourceAllocation->cost_status,
                'credit_journal_status' => $creditJournal->status, 'credit_warehouse_id' => $credit->warehouse_id, 'original_warehouse_id' => $original->warehouse_id,
                'movement_warehouse_id' => $movement->warehouse_id, 'credit_supplier_id' => $credit->supplier_id, 'original_supplier_id' => $original->supplier_id,
                'credit_receipt_line_id' => $creditAlloc->goods_receipt_line_id, 'original_receipt_line_id' => $originalAlloc->goods_receipt_line_id,
                'revision' => (int) $credit->reversal_revision,
            ], ['date' => $date, 'reason' => $reason]);
            $reversalMovement = $this->movements->reverseWithinTransaction($movement, ['idempotency_key' => $plan['idempotency_key'].':movement', 'business_date' => $date, 'created_by' => $actor->id, 'parent_allocation_id' => $sourceAllocation->id]);
            $reversalAllocations = CostAllocation::query()->where('stock_movement_id', $reversalMovement->id)->where('status', '!=', 'REVERSED')->lockForUpdate()->get();
            if ($reversalAllocations->count() !== 1) {
                throw ValidationException::withMessages(['allocation' => 'Reversal Movement ต้องสร้าง Cost Allocation เพียงหนึ่งรายการ (พบ '.$reversalAllocations->count().')']);
            }
            $reversalAllocation = $reversalAllocations->sole();
            if ($reversalAllocation->cost_status !== 'FINAL') {
                throw ValidationException::withMessages(['allocation' => 'Reversal Cost Allocation ต้องมี cost status FINAL ('.$reversalAllocation->status.'/'.$reversalAllocation->cost_status.')']);
            }
            if ((int) $reversalAllocation->parent_allocation_id !== (int) $sourceAllocation->id) {
                throw ValidationException::withMessages(['allocation' => 'Reversal allocation parent ไม่ตรงกับ allocation ต้นทาง']);
            }
            $this->allocations->linkJournalLineWithinTransaction($reversalAllocation, $creditLineJournal->sole());
            $totals = $this->reconciliation->totals($date, (int) $credit->warehouse_id, (int) $movement->item_id);
            if (($totals['status'] ?? null) !== 'ตรงกัน' || (int) ($totals['unlinked_allocations'] ?? 0) !== 0) {
                throw ValidationException::withMessages(['reconciliation' => 'Reversal ต้องผ่าน reconciliation ก่อนบันทึก']);
            }
            $credit->forceFill(['reversal_status' => 'REVERSED', 'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reason, 'reversal_revision' => $plan['revision'], 'inventory_reversal_movement_id' => $reversalMovement->id, 'inventory_reversal_allocation_id' => $reversalAllocation->id])->save();

            return $credit->fresh();
        }, 3);
    }
}
