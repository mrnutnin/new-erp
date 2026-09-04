<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostAllocationJournalLine;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\InventoryPurchaseReversalContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Closed-by-default live reversal boundary. A purchase with more than one
 * inventory movement is rejected until the multi-line journal allocation
 * resolver is available; partial reversal is never guessed.
 */
final class InventoryPurchaseLiveReversalAdapter
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly StockMovementService $movements,
        private readonly InventoryCostAllocationService $allocations,
        private readonly InventoryReconciliationService $reconciliation,
    ) {}

    public function reverse(PurchaseDocument $document, string $date, string $reason, User $actor, bool $featureEnabled = false): PurchaseDocument
    {
        if (! $featureEnabled) {
            throw ValidationException::withMessages(['reversal' => 'Inventory Purchase reversal ยังไม่เปิดใช้งาน']);
        }

        return DB::transaction(function () use ($document, $date, $reason, $actor): PurchaseDocument {
            $locked = PurchaseDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($locked->reversal_status === 'REVERSED') {
                if (! $locked->reversal_journal_entry_id) {
                    throw ValidationException::withMessages(['reversal' => 'เอกสารมีสถานะ Reversed แต่ไม่พบ Journal audit']);
                }

                $existingJournal = JournalEntry::query()->lockForUpdate()->findOrFail($locked->reversal_journal_entry_id);
                if ((string) $existingJournal->entry_date->format('Y-m-d') !== $date
                    || (string) $locked->reversal_reason !== $reason
                    || $existingJournal->status !== 'POSTED'
                    || (int) $existingJournal->reversal_of_id !== (int) $locked->journal_entry_id) {
                    throw ValidationException::withMessages(['reversal' => 'Reversal identity เดิมไม่ตรงกับคำขอใหม่']);
                }

                return $locked->fresh();
            }
            if ($locked->status !== 'POSTED' || ! $locked->journal_entry_id) {
                throw ValidationException::withMessages(['status' => 'กลับรายการได้เฉพาะ Purchase ที่ Post แล้ว']);
            }

            $journal = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($locked->journal_entry_id);
            if ((int) $journal->warehouse_id !== (int) $locked->warehouse_id
                || $journal->source_type !== 'PURCHASING'
                || ! in_array($journal->source_event, ['supplier_invoice.inventory', 'purchase_credit_note'], true)
                || (string) $journal->source_id !== (string) $locked->id) {
                throw ValidationException::withMessages(['journal_entry_id' => 'Journal ต้นทางไม่ตรงกับ Purchase และ Warehouse ของเอกสาร']);
            }
            $movements = StockMovement::query()->where('source_type', 'PURCHASING')->where('source_id', (string) $locked->id)
                ->where('status', 'POSTED')->lockForUpdate()->get();
            if ($movements->count() !== 1) {
                throw ValidationException::withMessages(['movement' => 'ต้องมี Inventory Movement ที่ Posted เพียงหนึ่งรายการก่อนเปิด multi-line reversal']);
            }
            $movement = $movements->sole();
            if ((int) $movement->warehouse_id !== (int) $locked->warehouse_id) {
                throw ValidationException::withMessages(['movement' => 'Inventory Movement ไม่อยู่ Warehouse เดียวกับ Purchase']);
            }
            $sourceAllocation = CostAllocation::query()->where('stock_movement_id', $movement->id)->where('status', '!=', 'REVERSED')->lockForUpdate()->get();
            if ($sourceAllocation->count() !== 1) {
                throw ValidationException::withMessages(['allocation' => 'ต้องมี Cost Allocation ต้นทางเพียงหนึ่งรายการเพื่อทำ reversal แบบ exact']);
            }
            $sourceAllocation = $sourceAllocation->sole();
            if ((int) $sourceAllocation->warehouse_id !== (int) $movement->warehouse_id
                || (int) $sourceAllocation->item_id !== (int) $movement->item_id
                || (int) $sourceAllocation->uom_id !== (int) $movement->uom_id) {
                throw ValidationException::withMessages(['allocation' => 'Cost Allocation ไม่ตรงกับ Movement แบบ exact']);
            }
            $plan = InventoryPurchaseReversalContract::plan([
                'document_id' => $locked->id, 'journal_id' => $journal->id, 'movement_id' => $movement->id,
                'allocation_id' => $sourceAllocation->id, 'revision' => (int) $locked->reversal_revision,
                'document_status' => $locked->status, 'movement_status' => $movement->status,
                'allocation_status' => $sourceAllocation->status,
            ], ['reason' => $reason, 'date' => $date]);

            $reversalJournal = $this->journals->reverseWithinTransaction($journal, [
                'source_type' => 'PURCHASING', 'source_id' => $plan['idempotency_key'], 'reversal_date' => $date, 'reason' => $reason,
            ], $actor);
            $reversalMovement = $this->movements->reverseWithinTransaction($movement, [
                'idempotency_key' => $plan['idempotency_key'].':movement', 'business_date' => $date, 'created_by' => $actor->id,
            ]);
            $reversalAllocations = CostAllocation::query()->where('stock_movement_id', $reversalMovement->id)->where('status', '!=', 'REVERSED')->lockForUpdate()->get();
            if ($reversalAllocations->count() !== 1) {
                throw ValidationException::withMessages(['allocation' => 'Reversal Movement ต้องสร้าง Cost Allocation exactly 1 รายการ']);
            }
            $reversalAllocation = $reversalAllocations->sole();
            if ($reversalAllocation->parent_allocation_id !== null && (int) $reversalAllocation->parent_allocation_id !== (int) $sourceAllocation->id) {
                throw ValidationException::withMessages(['allocation' => 'Reversal allocation parent ไม่ตรงกับ allocation ต้นทาง']);
            }
            if ($reversalAllocation->parent_allocation_id === null) {
                $reversalAllocation->forceFill([
                    'parent_allocation_id' => $sourceAllocation->id,
                    'metadata' => [
                        ...(is_array($reversalAllocation->metadata) ? $reversalAllocation->metadata : []),
                        'reversal_of_allocation_id' => $sourceAllocation->id,
                        'reversal_of_movement_id' => $movement->id,
                    ],
                ])->save();
            }
            $originalLink = CostAllocationJournalLine::query()->where('allocation_id', $sourceAllocation->id)->lockForUpdate()->first();
            $originalLine = $originalLink
                ? JournalEntryLine::query()->whereKey($originalLink->journal_entry_line_id)->where('journal_entry_id', $journal->id)->first()
                : null;
            if (! $originalLine) {
                throw ValidationException::withMessages(['journal_line' => 'ไม่พบ Journal linkage ของ allocation ต้นทาง']);
            }
            $reversalLines = JournalEntryLine::query()->where('journal_entry_id', $reversalJournal->id)
                ->where('account_id', $originalLine->account_id)->where('subledger_type', $originalLine->subledger_type)
                ->where('subledger_id', $originalLine->subledger_id)->where('debit', $originalLine->credit)->where('credit', $originalLine->debit)
                ->lockForUpdate()->get();
            if ($reversalLines->count() !== 1) {
                throw ValidationException::withMessages(['journal_line' => 'ไม่พบ Journal reversal line ที่ตรงกันแบบ exact']);
            }
            $this->allocations->linkJournalLineWithinTransaction($reversalAllocation, $reversalLines->sole());
            $after = $this->reconciliation->totals($date, (int) $locked->warehouse_id, (int) $movement->item_id);
            if (($after['status'] ?? null) !== 'ตรงกัน'
                || (int) ($after['unlinked_allocations'] ?? 0) !== 0
                || (string) ($after['allocation_vs_gl_difference'] ?? '0') !== '0.00000000'
                || (string) ($after['balance_vs_allocation_difference'] ?? '0') !== '0.00000000') {
                throw ValidationException::withMessages(['reconciliation' => 'ยอดหลัง reversal ต้อง reconcile เป็นศูนย์และไม่มี allocation ที่ยังไม่ผูก Journal']);
            }

            $locked->forceFill([
                'reversal_status' => 'REVERSED', 'reversal_journal_entry_id' => $reversalJournal->id,
                'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reason,
                'reversal_revision' => $plan['revision'],
            ])->save();

            return $locked->fresh();
        }, 3);
    }
}
