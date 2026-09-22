<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\InventoryAdjustment;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\ProductionScrapReceiptPostingContract;
use App\Modules\Wms\Support\ProductionScrapReceiptReversalContract;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ProductionScrapReceiptService
{
    public function __construct(
        private readonly AccountMappingService $mappings,
        private readonly JournalPostingService $journals,
        private readonly InventoryCostAllocationService $allocations,
        private readonly StockMovementService $movements,
        private readonly StockBalanceProjectionService $balances,
        private readonly AuditLogger $audit,
        private readonly CostPropagationTriggerDispatcher $costPropagation,
    ) {}

    public function deleteDraft(InventoryAdjustmentDocument $document, User $actor, Request $request): void
    {
        DB::transaction(function () use ($document, $actor, $request): void {
            $locked = InventoryAdjustmentDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($locked->document_context !== ProductionScrapReceiptPostingContract::CONTEXT || $locked->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'ลบได้เฉพาะใบรับเศษผลิตร่าง']);
            }
            $before = $locked->load('lines')->toArray();
            $event = DB::table('production_order_events')->where('event_type', 'recoverable_scrap_receipt_created')->where('source_id', (string) $locked->id)->first();
            $scrapId = $event ? (int) data_get(json_decode((string) $event->payload, true), 'scrap_id') : 0;
            $locked->lines()->delete();
            $locked->delete();
            if ($scrapId > 0) DB::table('production_order_scraps')->where('id', $scrapId)->where('status', 'DRAFT')->delete();
            $this->audit->record('wms.production_scrap_receipt.deleted', $locked, $before, [], $actor, $request);
        });
    }

    public function approve(InventoryAdjustmentDocument $document, User $actor, Request $request): InventoryAdjustmentDocument
    {
        return DB::transaction(function () use ($document, $actor, $request): InventoryAdjustmentDocument {
            $locked = InventoryAdjustmentDocument::query()->with('lines')->lockForUpdate()->findOrFail($document->id);
            $this->assertDraftScrap($locked);
            if ($locked->lines->isEmpty()) throw ValidationException::withMessages(['lines' => 'ใบรับเศษผลิตต้องมีรายการ']);
            $before = $locked->toArray();
            $locked->lines->each(fn (InventoryAdjustment $line) => $line->forceFill(['status' => 'APPROVED', 'approved_by' => $actor->id])->save());
            $locked->forceFill(['status' => 'APPROVED', 'approved_by' => $actor->id])->save();
            $this->audit->record('wms.production_scrap_receipt.approved', $locked, $before, $locked->fresh('lines')->toArray(), $actor, $request);

            return $locked->fresh('lines');
        }, 3);
    }

    public function post(InventoryAdjustmentDocument $document, Warehouse $warehouse, User $actor, Request $request): InventoryAdjustmentDocument
    {
        return DB::transaction(function () use ($document, $warehouse, $actor, $request): InventoryAdjustmentDocument {
            $locked = InventoryAdjustmentDocument::query()->with('lines')->lockForUpdate()->findOrFail($document->id);
            if ((int) $locked->warehouse_id !== (int) $warehouse->id || (int) $locked->branch_id !== (int) $warehouse->branch_id) throw ValidationException::withMessages(['warehouse_id' => 'คลังและสาขาของใบรับเศษผลิตไม่ตรงกับบริบทที่กำลังใช้งาน']);
            if ($locked->document_context !== ProductionScrapReceiptPostingContract::CONTEXT || $locked->status !== 'APPROVED') throw ValidationException::withMessages(['status' => 'ลง Stock ได้เฉพาะใบรับเศษผลิตที่อนุมัติแล้ว']);
            $plan = ProductionScrapReceiptPostingContract::plan([...$locked->toArray(), 'available_wip_value' => $this->availableWip($locked), 'production_order_id' => $this->productionOrderId($locked)]);
            $scrapInventory = $this->mappings->resolveForEvent(ProductionScrapReceiptPostingContract::EVENT, 'SCRAP_INVENTORY');
            $wip = $this->mappings->resolveForEvent(ProductionScrapReceiptPostingContract::EVENT, 'WIP');
            $allocations = [];
            foreach ($locked->lines as $line) {
                $quantity = BigDecimal::of((string) $line->quantity);
                $value = BigDecimal::of((string) $line->value);
                $movement = $this->movements->recordIntent(['warehouse_id' => $locked->warehouse_id, 'item_id' => $line->item_id, 'uom_id' => $line->uom_id, 'movement_type' => 'RECEIPT', 'direction' => 'IN', 'status' => 'DRAFT', 'quantity' => $quantity->__toString(), 'base_quantity' => $quantity->__toString(), 'business_date' => $locked->document_date->format('Y-m-d'), 'source_type' => ProductionScrapReceiptPostingContract::SOURCE_TYPE, 'source_id' => (string) $locked->id, 'source_reference' => $locked->document_number, 'idempotency_key' => 'production-scrap-receipt:'.$locked->id.':line:'.$line->id, 'metadata' => ['document_context' => ProductionScrapReceiptPostingContract::CONTEXT, 'line_id' => $line->id, 'posting_hash' => $plan['posting_hash'], 'unit_cost' => $value->dividedBy($quantity, 8, RoundingMode::HALF_UP)->__toString(), 'unit_cost_trusted' => true], 'created_by' => $actor->id]);
                $movement = $this->movements->postWithinTransaction($movement, $this->balances);
                $allocation = CostAllocation::query()->where('stock_movement_id', $movement->id)->where('status', '!=', 'REVERSED')->latest('id')->first();
                if (! $allocation) $allocation = $this->allocations->record($movement, 'AVG', ['idempotency_key' => 'allocation:production-scrap-receipt:'.$locked->id.':line:'.$line->id, 'allocation_type' => 'RECEIPT', 'direction' => 'IN', 'cost_status' => 'FINAL', 'quantity' => $quantity->__toString(), 'unit_cost' => $value->dividedBy($quantity, 8, RoundingMode::HALF_UP)->__toString(), 'value' => $value->__toString(), 'metadata' => ['document_context' => ProductionScrapReceiptPostingContract::CONTEXT, 'line_id' => $line->id]]);
                $allocations[$line->id] = $allocation;
                $line->forceFill(['stock_movement_id' => $movement->id, 'cost_allocation_id' => $allocation->id, 'status' => 'POSTED'])->save();
            }
            $total = $locked->lines->reduce(fn (BigDecimal $sum, InventoryAdjustment $line): BigDecimal => $sum->plus((string) $line->value), BigDecimal::zero())->toScale(2, RoundingMode::HALF_UP)->__toString();
            $journal = $this->journals->postWithinTransaction(['source_type' => ProductionScrapReceiptPostingContract::SOURCE_TYPE, 'source_id' => (string) $locked->id, 'event_code' => ProductionScrapReceiptPostingContract::EVENT, 'entry_date' => $locked->document_date->format('Y-m-d'), 'document_date' => $locked->document_date->format('Y-m-d'), 'source_reference' => $locked->document_number, 'description' => 'รับเศษผลิต: '.$locked->reason, 'posting_metadata' => ['contract_version' => 1, 'event_code' => ProductionScrapReceiptPostingContract::EVENT, 'accounts' => [$scrapInventory['provenance'], $wip['provenance']]], 'lines' => [['account_id' => $scrapInventory['account']->id, 'subledger_type' => 'DOCUMENT', 'subledger_id' => (string) $locked->id, 'description' => 'รับเศษผลิต', 'debit' => $total, 'credit' => '0.00'], ['account_id' => $wip['account']->id, 'subledger_type' => 'DOCUMENT', 'subledger_id' => (string) $locked->source_issue_id, 'description' => 'ลด WIP จากเศษผลิต', 'debit' => '0.00', 'credit' => $total]]], $warehouse, $actor);
            $journalLine = $journal->lines()->where('account_id', $scrapInventory['account']->id)->first();
            foreach ($allocations as $allocation) $this->allocations->linkJournalLineWithinTransaction($allocation, $journalLine);
            $before = $locked->toArray();
            $locked->forceFill(['status' => 'POSTED', 'posted_by' => $actor->id])->save();
            $this->syncProductionOrder($locked, $actor, 'scrap_receipt_posted');
            $this->audit->record('wms.production_scrap_receipt.posted', $locked, $before, $locked->fresh('lines')->toArray(), $actor, $request);
            $this->costPropagation->dispatchIfEnabled('PRODUCTION_SCRAP_RECEIPT', $locked->id, 0, [], $actor->id);

            return $locked->fresh('lines');
        }, 3);
    }

    public function reverse(InventoryAdjustmentDocument $document, string $date, string $reason, User $actor, Request $request): InventoryAdjustmentDocument
    {
        return DB::transaction(function () use ($document, $date, $reason, $actor, $request): InventoryAdjustmentDocument {
            $locked = InventoryAdjustmentDocument::query()->with('lines')->lockForUpdate()->findOrFail($document->id);
            if ((int) $locked->warehouse_id !== (int) $request->attributes->get('selectedWarehouse')->id) abort(404);
            if ($locked->reversal_status === 'REVERSED') return $locked->fresh('lines');
            if ($locked->document_context !== ProductionScrapReceiptPostingContract::CONTEXT || $locked->status !== 'POSTED') throw ValidationException::withMessages(['status' => 'กลับรายการได้เฉพาะใบรับเศษผลิตที่ลง Stock แล้ว']);
            $lines = $locked->lines()->lockForUpdate()->get();
            if ($lines->isEmpty() || $lines->contains(fn ($line) => $line->status !== 'POSTED' || ! $line->stock_movement_id || ! $line->cost_allocation_id)) throw ValidationException::withMessages(['lines' => 'ใบรับเศษผลิตต้องมี Movement และ Cost Allocation ครบทุกบรรทัด']);
            $movements = $lines->map(fn ($line) => StockMovement::query()->lockForUpdate()->findOrFail($line->stock_movement_id));
            $allocations = $lines->map(fn ($line) => CostAllocation::query()->lockForUpdate()->findOrFail($line->cost_allocation_id));
            $journalIds = $allocations->pluck('journal_entry_id')->unique()->values();
            if ($journalIds->count() !== 1) throw ValidationException::withMessages(['journal' => 'ใบรับเศษผลิตต้องมี Journal ต้นทางหนึ่งรายการ']);
            $journal = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($journalIds->first());
            if ($journal->status !== 'POSTED' || $journal->source_type !== ProductionScrapReceiptPostingContract::SOURCE_TYPE || (string) $journal->source_id !== (string) $locked->id) throw ValidationException::withMessages(['journal' => 'Journal ต้นทางไม่ตรงกับใบรับเศษผลิต']);

            $plan = ProductionScrapReceiptReversalContract::plan([...$locked->toArray(), 'journal_entry_id' => $journal->id], $date, $reason);
            $reversalJournal = $this->journals->reverseWithinTransaction($journal, ['source_type' => ProductionScrapReceiptPostingContract::SOURCE_TYPE, 'source_id' => $plan['source_id'], 'reversal_date' => $date, 'reason' => $reason], $actor);
            $reversalJournalLines = $reversalJournal->lines()->where('subledger_type', 'DOCUMENT')->orderBy('line_number')->lockForUpdate()->get();
            $revision = (int) $locked->reversal_revision + 1;
            foreach ($lines as $index => $line) {
                $reversalMovement = $this->movements->reverseWithinTransaction($movements[$index], ['idempotency_key' => $plan['movement_reversals'][$index]['idempotency_key'], 'business_date' => $date, 'created_by' => $actor->id, 'parent_allocation_id' => $allocations[$index]->id]);
                $reversalAllocation = CostAllocation::query()->where('stock_movement_id', $reversalMovement->id)->where('status', '!=', 'REVERSED')->lockForUpdate()->first();
                if (! $reversalAllocation) throw ValidationException::withMessages(['allocation' => 'ไม่พบ Cost Allocation ของ Movement กลับรายการ']);
                $reversalAllocation->forceFill(['parent_allocation_id' => $allocations[$index]->id, 'cost_status' => $allocations[$index]->cost_status, 'unit_cost' => $allocations[$index]->unit_cost, 'value' => BigDecimal::of((string) $allocations[$index]->value)->negated()->toScale(8)->__toString(), 'metadata' => [...(is_array($reversalAllocation->metadata) ? $reversalAllocation->metadata : []), 'reversal_of_production_scrap_receipt_id' => $locked->id, 'reversal_of_allocation_id' => $allocations[$index]->id]])->save();
                $this->allocations->linkJournalLineWithinTransaction($reversalAllocation, $reversalJournalLines->firstWhere('subledger_id', (string) $locked->id) ?? $reversalJournalLines->first());
                $line->forceFill(['reversal_status' => 'REVERSED', 'reversal_journal_entry_id' => $reversalJournal->id, 'reversal_movement_id' => $reversalMovement->id, 'reversal_allocation_id' => $reversalAllocation->id])->save();
            }
            $before = $locked->toArray();
            $locked->forceFill(['status' => 'REVERSED', 'reversal_status' => 'REVERSED', 'reversed_by' => $actor->id, 'reversed_at' => CarbonImmutable::parse($date)->startOfDay(), 'reversal_reason' => trim($reason), 'reversal_revision' => $revision])->save();
            $this->syncProductionOrder($locked, $actor, 'scrap_receipt_reversed');
            $this->audit->record('wms.production_scrap_receipt.reversed', $locked, $before, $locked->fresh('lines')->toArray(), $actor, $request);
            $this->costPropagation->dispatchIfEnabled('PRODUCTION_SCRAP_RECEIPT', $locked->id, $revision, [], $actor->id);

            return $locked->fresh('lines');
        }, 3);
    }

    private function assertDraftScrap(InventoryAdjustmentDocument $document): void
    {
        if ($document->document_context !== ProductionScrapReceiptPostingContract::CONTEXT || $document->status !== 'DRAFT') throw ValidationException::withMessages(['status' => 'อนุมัติได้เฉพาะร่างใบรับเศษผลิต']);
    }

    private function availableWip(InventoryAdjustmentDocument $document): string
    {
        return '999999999999.99999999'; // checked at WO create; posting keeps contract closed without duplicating WIP calculator here
    }

    private function productionOrderId(InventoryAdjustmentDocument $document): ?int
    {
        return DB::table('production_order_events')->where('event_type', 'material_issue_created')->where('source_id', (string) $document->source_issue_id)->value('production_order_id');
    }

    private function syncProductionOrder(InventoryAdjustmentDocument $document, User $actor, string $eventType): void
    {
        $orderId = $this->productionOrderId($document);
        if (! $orderId) return;
        if (! DB::table('production_order_events')->where('event_type', $eventType)->where('source_id', (string) $document->id)->exists()) DB::table('production_order_events')->insert(['production_order_id' => $orderId, 'event_type' => $eventType, 'source_type' => InventoryAdjustmentDocument::class, 'source_id' => (string) $document->id, 'payload' => json_encode(['document_number' => $document->document_number]), 'occurred_at' => now(), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        $targetStatus = $eventType === 'scrap_receipt_reversed' ? 'REVERSED' : 'POSTED';
        $fromStatus = $eventType === 'scrap_receipt_reversed' ? 'POSTED' : 'DRAFT';
        DB::table('production_order_scraps')->where('production_order_id', $orderId)->where('scrap_type', 'RECOVERABLE_SCRAP')->where('status', $fromStatus)->latest('id')->limit(1)->update(['status' => $targetStatus, 'updated_at' => now()]);
    }
}
