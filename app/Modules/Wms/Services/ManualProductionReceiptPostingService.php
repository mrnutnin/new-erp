<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Accounting\Support\PostingEvent;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Models\StockReservation;
use App\Modules\Wms\Support\InventoryReconciliationCalculator;
use App\Modules\Wms\Support\InventoryReconciliationGate;
use App\Modules\Wms\Support\ManualProductionReceiptContract;
use App\Modules\Wms\Support\ManualProductionReceiptPostingContract;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Atomic posting boundary for Manual Finished Receipt.
 *
 * Wave 6 deliberately keeps the write path closed. Once the event is LIVE
 * and the feature flag is enabled, this service is the single owner of the
 * outer transaction that will execute the returned posting plan.
 */
final class ManualProductionReceiptPostingService
{
    public function __construct(
        private readonly AccountMappingService $mappings,
        private readonly JournalPostingService $journals,
        private readonly StockMovementService $movements,
        private readonly InventoryCostAllocationService $allocations,
        private readonly StockBalanceProjectionService $balances,
        private readonly StockReservationService $reservations,
        private readonly AuditLogger $audit,
        private readonly CostPropagationTriggerDispatcher $costPropagation,
    ) {}

    public function preflight(array $document): array
    {
        $plan = ManualProductionReceiptPostingContract::plan($document);
        $mapping = $this->mappings->readiness($plan['event_code']);
        $event = PostingEvent::contract($plan['event_code']);
        $blockers = $mapping['blockers'] ?? [];
        $enabled = (bool) config('erp.inventory.manual_production_receipt_posting_enabled', false);

        if (! $enabled) {
            $blockers[] = [
                'code' => 'FEATURE_DISABLED',
                'message' => 'Manual Finished Receipt Posting ยังไม่เปิดใช้งาน',
            ];
        }
        if (($event['status'] ?? null) !== 'LIVE') {
            $blockers[] = [
                'code' => 'POSTING_EVENT_NOT_LIVE',
                'message' => 'Production Finished Receipt event ยังไม่อยู่ในสถานะ LIVE',
            ];
        }
        $reconciliation = $this->reconciliation($document);
        $blockers = [...$blockers, ...$reconciliation['blockers'], ...$this->productionOrderReceiptLimitBlockers($document)];

        return [
            'ready' => $blockers === [],
            'feature_enabled' => $enabled,
            'event_status' => $event['status'] ?? null,
            'plan' => $plan,
            'mapping' => $mapping,
            'reconciliation' => $reconciliation,
            'blockers' => array_values($blockers),
        ];
    }

    public function post(InventoryAdjustmentDocument $document, Warehouse $warehouse, User $actor, Request $request): InventoryAdjustmentDocument
    {
        $document->load('lines');
        $preflight = $this->preflight($document->toArray());
        if (($preflight['ready'] ?? false) !== true) {
            throw ValidationException::withMessages([
                'posting' => collect($preflight['blockers'])->pluck('message')->implode(' '),
            ]);
        }

        return DB::transaction(function () use ($document, $warehouse, $actor, $request): InventoryAdjustmentDocument {
            $locked = InventoryAdjustmentDocument::query()->with('lines')->lockForUpdate()->findOrFail($document->id);
            if ((int) $locked->warehouse_id !== (int) $warehouse->id || (int) $locked->branch_id !== (int) $warehouse->branch_id) {
                throw ValidationException::withMessages(['warehouse_id' => 'คลังและสาขาของใบรับผลิตไม่ตรงกับบริบทที่กำลังใช้งาน']);
            }
            if ($locked->document_context !== 'PRODUCTION_RECEIPT' || $locked->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => 'ลงบัญชีใบรับผลิตได้เฉพาะเอกสารที่อนุมัติแล้ว']);
            }
            $limitBlockers = $this->productionOrderReceiptLimitBlockers($locked->toArray());
            if ($limitBlockers !== []) {
                throw ValidationException::withMessages(['posting' => collect($limitBlockers)->pluck('message')->implode(' ')]);
            }

            $beforeBalances = $this->snapshotBalances($locked->lines);

            $finishedGoods = $this->mappings->resolveForEvent('production.finished_receipt', 'FINISHED_GOODS');
            $wip = $this->mappings->resolveForEvent('production.finished_receipt', 'WIP');
            $movementRows = [];
            $allocationRows = [];

            foreach ($locked->lines as $line) {
                $identity = 'production-receipt:'.$locked->id.':line:'.$line->id;
                $quantity = BigDecimal::of((string) $line->quantity);
                $value = BigDecimal::of((string) $line->value);
                $unitCost = $value->dividedBy($quantity, 8, RoundingMode::HALF_UP);
                $movement = $this->movements->recordIntent([
                    'warehouse_id' => $locked->warehouse_id,
                    'item_id' => $line->item_id,
                    'uom_id' => $line->uom_id,
                    'movement_type' => 'RECEIPT',
                    'direction' => 'IN',
                    'status' => 'DRAFT',
                    'quantity' => $quantity->toScale(8)->__toString(),
                    'base_quantity' => $quantity->toScale(8)->__toString(),
                    'business_date' => $locked->document_date->format('Y-m-d'),
                    'source_type' => 'WMS_PRODUCTION_RECEIPT',
                    'source_id' => (string) $locked->id,
                    'source_reference' => $locked->document_number,
                    'idempotency_key' => $identity,
                    'metadata' => [
                        'document_context' => 'PRODUCTION_RECEIPT',
                        'line_id' => $line->id,
                        'unit_cost' => $unitCost->toScale(8, RoundingMode::HALF_UP)->__toString(),
                        'receipt_value' => $value->toScale(8, RoundingMode::HALF_UP)->__toString(),
                        'unit_cost_trusted' => true,
                    ],
                    'created_by' => $actor->id,
                ]);
                $movement = $this->movements->postWithinTransaction($movement, $this->balances);
                // AVG posting may already create the canonical receipt
                // allocation while valuing the IN movement. Reuse it rather
                // than creating a second pending allocation for the same
                // movement; non-AVG methods still create the allocation here.
                $allocation = CostAllocation::query()
                    ->where('stock_movement_id', $movement->id)
                    ->where('status', '!=', 'REVERSED')
                    ->orderByDesc('id')
                    ->first();
                if (! $allocation) {
                    $allocation = $this->allocations->record($movement, 'AVG', [
                        'idempotency_key' => 'allocation:'.$identity,
                        'allocation_type' => 'RECEIPT',
                        'direction' => 'IN',
                        'cost_status' => 'FINAL',
                        'quantity' => $quantity->toScale(8)->__toString(),
                        'unit_cost' => $unitCost->toScale(8, RoundingMode::HALF_UP)->__toString(),
                        'value' => $value->toScale(8)->__toString(),
                        'metadata' => ['document_context' => 'PRODUCTION_RECEIPT', 'line_id' => $line->id],
                    ]);
                }
                if (! BigDecimal::of((string) $allocation->value)->toScale(8, RoundingMode::HALF_UP)->isEqualTo($value->toScale(8, RoundingMode::HALF_UP))) {
                    throw ValidationException::withMessages(['posting' => 'ต้นทุน Cost Allocation ของรายการรับผลิตไม่ตรงกับต้นทุนที่บันทึกไว้']);
                }
                $movementRows[$line->id] = $movement;
                $allocationRows[$line->id] = $allocation;
            }

            $journalLines = [];
            foreach ($locked->lines as $line) {
                $amount = BigDecimal::of((string) $line->value)->toScale(2, RoundingMode::HALF_UP)->__toString();
                $journalLines[] = ['account_id' => $finishedGoods['account']->id, 'subledger_type' => 'ITEM', 'subledger_id' => (string) $line->item_id, 'description' => 'รับสินค้าผลิตเสร็จ '.$line->item_id, 'debit' => $amount, 'credit' => '0.00'];
            }
            $total = collect($journalLines)->reduce(fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['debit']), BigDecimal::zero())->toScale(2, RoundingMode::HALF_UP)->__toString();
            $journalLines[] = ['account_id' => $wip['account']->id, 'subledger_type' => 'DOCUMENT', 'subledger_id' => (string) $locked->id, 'description' => 'โอนต้นทุนจากงานระหว่างทำ', 'debit' => '0.00', 'credit' => $total];
            $journal = $this->journals->postWithinTransaction([
                'source_type' => 'WMS_PRODUCTION_RECEIPT', 'source_id' => (string) $locked->id,
                'event_code' => 'production.finished_receipt', 'entry_date' => $locked->document_date->format('Y-m-d'),
                'document_date' => $locked->document_date->format('Y-m-d'), 'source_reference' => $locked->document_number,
                'description' => 'รับสินค้าผลิตเสร็จ: '.$locked->reason,
                // Variance is required by the event readiness contract, but
                // this MVP enforces receipt value == issued cost. Therefore
                // no variance journal line is created when the difference is
                // zero; metadata contains only accounts present in the entry.
                'posting_metadata' => ['contract_version' => 1, 'event_code' => 'production.finished_receipt', 'accounts' => [$finishedGoods['provenance'], $wip['provenance']]],
                'lines' => $journalLines,
            ], $warehouse, $actor);
            $journal->load('lines');
            foreach ($locked->lines as $line) {
                $allocation = $allocationRows[$line->id];
                $journalLine = $journal->lines->first(fn ($journalLine): bool => (int) $journalLine->account_id === (int) $finishedGoods['account']->id && (string) $journalLine->subledger_id === (string) $line->item_id);
                $this->allocations->linkJournalLineWithinTransaction($allocation, $journalLine);
                $line->forceFill(['stock_movement_id' => $movementRows[$line->id]->id, 'cost_allocation_id' => $allocation->id, 'status' => 'POSTED'])->save();
            }
            $reconciliation = $this->postedReconciliation($locked, $journal, $beforeBalances, $movementRows, $allocationRows, (int) $finishedGoods['account']->id);
            if (! $reconciliation['ready']) {
                throw ValidationException::withMessages([
                    'posting' => 'Gate C ไม่ผ่าน: '.implode(' ', $reconciliation['blockers']),
                ]);
            }
            $before = $locked->toArray();
            $locked->forceFill(['status' => 'POSTED', 'posted_by' => $actor->id])->save();
            $this->syncProductionOrderCompletion($locked, $actor);
            $this->audit->record('wms.inventory_adjustment.posted', $locked, $before, $locked->fresh()->load('lines')->toArray(), $actor, $request);
            $this->costPropagation->dispatchIfEnabled('PRODUCTION_FINISHED_RECEIPT', $locked->id, 0, [], $actor->id);

            return $locked->fresh();
        }, 3);
    }

    private function productionOrderReceiptLimitBlockers(array $document): array
    {
        if (($document['document_context'] ?? null) !== ManualProductionReceiptContract::CONTEXT
            || ! Schema::hasTable('production_order_events')
            || ! Schema::hasTable('production_orders')
            || ! Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id')) {
            return [];
        }
        $sourceIssueId = (int) ($document['source_issue_id'] ?? 0);
        if ($sourceIssueId <= 0) return [];
        $event = DB::table('production_order_events')->where('event_type', 'material_issue_created')->where('source_id', (string) $sourceIssueId)->latest('id')->first();
        if (! $event) return [];
        $order = DB::table('production_orders')->where('id', $event->production_order_id)->first();
        if (! $order) return [];

        $receiptId = (int) ($document['id'] ?? 0);
        $plannedQuantity = BigDecimal::of((string) $order->planned_quantity)->toScale(8, RoundingMode::HALF_UP);
        $currentQuantity = collect($document['lines'] ?? [])
            ->where('item_id', (int) $order->finished_item_id)
            ->reduce(fn (BigDecimal $sum, array $line): BigDecimal => $sum->plus((string) ($line['quantity'] ?? 0)), BigDecimal::zero())
            ->toScale(8, RoundingMode::HALF_UP);
        $blockers = [];
        if (! $currentQuantity->isEqualTo($plannedQuantity)) {
            $blockers[] = ['code' => 'PRODUCTION_ORDER_RECEIPT_NOT_PLANNED_QUANTITY', 'message' => '1 WO ต้องรับผลิตครั้งเดียวเท่ากับ Planned quantity'];
        }
        if (DB::table('wms_inventory_adjustment_documents')
            ->where('document_context', ManualProductionReceiptContract::CONTEXT)
            ->where('source_issue_id', $sourceIssueId)
            ->where('id', '!=', $receiptId)
            ->whereIn('status', ['DRAFT', 'APPROVED', 'POSTED'])
            ->where(fn ($q) => $q->whereNull('reversal_status')->orWhere('reversal_status', '!=', 'REVERSED'))
            ->whereNull('deleted_at')
            ->exists()) {
            $blockers[] = ['code' => 'PRODUCTION_ORDER_FINISHED_RECEIPT_EXISTS', 'message' => 'WO นี้มีใบรับผลิตแล้ว ต้องยกเลิก/กลับรายการก่อนสร้างใหม่'];
        }
        if ($blockers !== []) {
            return $blockers;
        }

        if (DB::table('wms_issue_returns')->where('issue_document_id', $sourceIssueId)->whereIn('status', ['DRAFT', 'APPROVED'])->whereNull('deleted_at')->exists()) {
            $blockers[] = ['code' => 'PRODUCTION_ORDER_PENDING_MATERIAL_RETURN', 'message' => 'มีใบรับคืนวัตถุดิบที่ยังไม่ลง Stock/ยกเลิก ต้องเคลียร์ก่อนปิด WO'];
        }
        if (DB::table('wms_inventory_adjustment_documents')->where('document_context', 'PRODUCTION_SCRAP_RECEIPT')->where('source_issue_id', $sourceIssueId)->whereIn('status', ['DRAFT', 'APPROVED'])->whereNull('deleted_at')->exists()) {
            $blockers[] = ['code' => 'PRODUCTION_ORDER_PENDING_SCRAP_RECEIPT', 'message' => 'มีใบรับเศษผลิตที่ยังไม่ลง Stock/ยกเลิก ต้องเคลียร์ก่อนปิด WO'];
        }

        return $blockers;
    }

    private function syncProductionOrderCompletion(InventoryAdjustmentDocument $receipt, User $actor): void
    {
        if ($receipt->document_context !== 'PRODUCTION_RECEIPT'
            || ! Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id')
            || ! Schema::hasTable('production_order_events')
            || ! Schema::hasTable('production_orders')) {
            return;
        }
        $event = DB::table('production_order_events')->where('event_type', 'material_issue_created')->where('source_id', (string) $receipt->source_issue_id)->latest('id')->first();
        if (! $event) {
            return;
        }
        $order = DB::table('production_orders')->where('id', $event->production_order_id)->lockForUpdate()->first();
        if (! $order || ! in_array($order->status, ['IN_PROGRESS', 'RELEASED'], true)) {
            return;
        }
        $completed = DB::table('wms_inventory_adjustments')
            ->join('wms_inventory_adjustment_documents', 'wms_inventory_adjustment_documents.id', '=', 'wms_inventory_adjustments.document_id')
            ->where('wms_inventory_adjustment_documents.document_context', 'PRODUCTION_RECEIPT')
            ->where('wms_inventory_adjustment_documents.status', 'POSTED')
            ->where('wms_inventory_adjustment_documents.source_issue_id', $receipt->source_issue_id)
            ->where('wms_inventory_adjustments.item_id', $order->finished_item_id)
            ->where('wms_inventory_adjustments.status', 'POSTED')
            ->sum('wms_inventory_adjustments.quantity');
        $completed = BigDecimal::of((string) $completed)->toScale(8, RoundingMode::HALF_UP);
        $planned = BigDecimal::of((string) $order->planned_quantity)->toScale(8, RoundingMode::HALF_UP);
        $done = ! $completed->isLessThan($planned);
        DB::table('production_orders')->where('id', $order->id)->update([
            'status' => $done ? 'COMPLETED' : 'IN_PROGRESS',
            'completed_quantity' => $completed->__toString(),
            'completed_at' => $done ? now() : null,
            'completed_by' => $done ? $actor->id : null,
            'updated_by' => $actor->id,
            'updated_at' => now(),
        ]);
        if ($done && $order->order_type === 'MAKE_TO_ORDER' && (int) $order->sales_order_line_id > 0) {
            $reservation = $this->reservations->reserve([
                'warehouse_id' => (int) $order->receipt_warehouse_id,
                'item_id' => (int) $order->finished_item_id,
                'uom_id' => (int) $order->uom_id,
                'quantity' => $planned->__toString(),
                'source_type' => StockReservation::SOURCE_SALES_ORDER_LINE,
                'source_id' => (string) $order->sales_order_line_id,
                'idempotency_key' => 'sales-order-line:'.$order->sales_order_line_id.':finished-goods',
                'created_by' => $actor->id,
            ]);
            if (! DB::table('production_order_events')->where('event_type', 'finished_goods_reserved')->where('source_id', (string) $reservation->id)->exists()) {
                DB::table('production_order_events')->insert(['production_order_id' => $order->id, 'event_type' => 'finished_goods_reserved', 'source_type' => StockReservation::class, 'source_id' => (string) $reservation->id, 'payload' => json_encode(['sales_order_line_id' => $order->sales_order_line_id, 'quantity' => $planned->__toString()]), 'occurred_at' => now(), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        if (! DB::table('production_order_events')->where('event_type', 'finished_receipt_posted')->where('source_id', (string) $receipt->id)->exists()) {
            DB::table('production_order_events')->insert(['production_order_id' => $order->id, 'event_type' => 'finished_receipt_posted', 'source_type' => InventoryAdjustmentDocument::class, 'source_id' => (string) $receipt->id, 'payload' => json_encode(['document_number' => $receipt->document_number, 'completed_quantity' => $completed->__toString(), 'completed' => $done]), 'occurred_at' => now(), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** Read-only Gate C proof: source issue, receipt value and source cost must reconcile before Post. */
    private function reconciliation(array $document): array
    {
        $blockers = [];
        $receiptId = (int) ($document['id'] ?? 0);
        $sourceId = (int) ($document['source_issue_id'] ?? 0);
        $bridgeRows = Schema::hasTable('wms_production_receipt_sources') && $receiptId > 0
            ? DB::table('wms_production_receipt_sources')->where('receipt_document_id', $receiptId)
                ->orderBy('position')->get(['issue_document_id', 'source_allocation_id', 'source_allocation_revision', 'consumed_quantity', 'consumed_value'])
            : collect();
        $sourceIds = $bridgeRows->isNotEmpty() ? $bridgeRows->pluck('issue_document_id')->unique()->values() : collect([$sourceId])->filter();
        $sources = IssueDocument::query()->with(['lines.movement', 'lines.costAllocations'])->whereIn('id', $sourceIds)->get();

        if ($sources->isEmpty() || $sources->count() !== $sourceIds->count()) {
            $blockers[] = ['code' => 'SOURCE_ISSUE_MISSING', 'message' => 'ไม่พบใบเบิกวัตถุดิบต้นทางสำหรับตรวจสอบต้นทุน'];

            return ['ready' => false, 'source_total' => '0.00000000', 'receipt_total' => $this->receiptTotal($document), 'variance' => '0.00000000', 'blockers' => $blockers];
        }
        foreach ($sources as $source) {
            if ($source->issue_type !== 'PRODUCTION' || $source->status !== 'POSTED') {
                $blockers[] = ['code' => 'SOURCE_ISSUE_NOT_POSTED', 'message' => 'ใบเบิกต้นทางทุกใบต้องเป็น Production และลง Stock แล้ว'];
            }
            if ((int) $source->warehouse_id !== (int) ($document['warehouse_id'] ?? 0)) {
                $blockers[] = ['code' => 'SOURCE_WAREHOUSE_MISMATCH', 'message' => 'Warehouse ของใบเบิกต้นทางต้องตรงกับใบรับผลิตทุกใบ'];
            }
        }

        if (StockMovement::query()->where('source_type', ManualProductionReceiptPostingContract::SOURCE_TYPE)->where('source_id', (string) $receiptId)->exists()) {
            $blockers[] = ['code' => 'RECEIPT_MOVEMENT_EXISTS', 'message' => 'พบ Stock Movement ของใบรับผลิตนี้อยู่แล้ว ห้ามสร้างซ้ำ'];
        }
        if (CostAllocation::query()->whereHas('movement', fn ($movement) => $movement->where('source_type', ManualProductionReceiptPostingContract::SOURCE_TYPE)->where('source_id', (string) $receiptId))->exists()) {
            $blockers[] = ['code' => 'RECEIPT_ALLOCATION_EXISTS', 'message' => 'พบ Cost Allocation ของใบรับผลิตนี้อยู่แล้ว ห้ามสร้างซ้ำ'];
        }
        if (JournalEntry::query()->where('source_type', ManualProductionReceiptPostingContract::SOURCE_TYPE)->where('source_event', 'production.finished_receipt')->where('source_id', (string) $receiptId)->exists()) {
            $blockers[] = ['code' => 'RECEIPT_JOURNAL_EXISTS', 'message' => 'พบ Journal ของใบรับผลิตนี้อยู่แล้ว ห้ามสร้างซ้ำ'];
        }

        foreach (($document['lines'] ?? []) as $index => $line) {
            $movementId = (int) ($line['stock_movement_id'] ?? 0);
            $allocationId = (int) ($line['cost_allocation_id'] ?? 0);
            if (($movementId > 0) !== ($allocationId > 0)) {
                $blockers[] = ['code' => 'RECEIPT_LINE_LINKAGE_INCOMPLETE', 'message' => 'รายการรับผลิตบรรทัดที่ '.($index + 1).' มี Movement/Allocation ไม่ครบคู่'];
            }
            if ($movementId > 0 && $allocationId > 0) {
                $allocation = CostAllocation::query()->with('movement')->find($allocationId);
                if (! $allocation || (int) $allocation->stock_movement_id !== $movementId || $allocation->movement?->source_type !== ManualProductionReceiptPostingContract::SOURCE_TYPE || (string) $allocation->movement?->source_id !== (string) $receiptId) {
                    $blockers[] = ['code' => 'RECEIPT_LINE_LINKAGE_MISMATCH', 'message' => 'Movement/Allocation ของรายการรับผลิตบรรทัดที่ '.($index + 1).' ไม่ตรงกับเอกสาร'];
                }
            }
        }

        $sourceTotal = BigDecimal::zero();
        if ($bridgeRows->isNotEmpty()) {
            $allocations = CostAllocation::query()->with('movement')->whereIn('id', $bridgeRows->pluck('source_allocation_id')->all())->get()->keyBy('id');
            foreach ($bridgeRows as $bridge) {
                $allocation = $allocations->get((int) $bridge->source_allocation_id);
                if (! $allocation || $allocation->status === 'REVERSED' || $allocation->cost_status !== 'FINAL') {
                    $blockers[] = ['code' => 'SOURCE_COST_NOT_FINAL', 'message' => 'ต้นทุนของใบเบิกต้นทางยังไม่ Final ครบทุก Allocation'];

                    continue;
                }
                if ((int) $allocation->revision !== (int) $bridge->source_allocation_revision) {
                    $blockers[] = ['code' => 'SOURCE_COST_REVISION_CHANGED', 'message' => 'ต้นทุนใบเบิกต้นทางถูกคำนวณใหม่หลังสร้าง Draft กรุณาแก้ไขและบันทึกใบรับผลิตอีกครั้ง'];

                    continue;
                }
                if ($allocation->movement?->status !== 'POSTED' || (int) $allocation->movement?->warehouse_id !== (int) ($document['warehouse_id'] ?? 0)) {
                    $blockers[] = ['code' => 'SOURCE_MOVEMENT_NOT_RECONCILED', 'message' => 'Stock Movement และ Cost Allocation ของใบเบิกต้นทางไม่สอดคล้องกัน'];

                    continue;
                }
                $sourceTotal = $sourceTotal->plus(BigDecimal::of((string) $bridge->consumed_value)->abs());
            }
        } else {
            foreach ($sources as $source) {
                foreach ($source->lines as $line) {
                    $movement = $line->movement;
                    $allocations = $line->costAllocations->where('status', '!=', 'REVERSED');
                    if (! $movement || $allocations->isEmpty() || $allocations->contains(fn ($allocation): bool => $allocation->cost_status !== 'FINAL')) {
                        $blockers[] = ['code' => 'SOURCE_COST_NOT_FINAL', 'message' => 'ต้นทุนของใบเบิกต้นทางยังไม่ Final ครบทุกบรรทัด'];

                        continue;
                    }
                    if ($movement->status !== 'POSTED' || (int) $movement->warehouse_id !== (int) $source->warehouse_id) {
                        $blockers[] = ['code' => 'SOURCE_MOVEMENT_NOT_RECONCILED', 'message' => 'Stock Movement และ Cost Allocation ของใบเบิกต้นทางไม่สอดคล้องกัน'];

                        continue;
                    }
                    foreach ($allocations as $allocation) {
                        $sourceTotal = $sourceTotal->plus(BigDecimal::of((string) $allocation->value)->abs());
                    }
                }
            }
        }
        $receiptTotal = BigDecimal::of($this->receiptTotal($document));
        $sourceTotal = $sourceTotal->toScale(8, RoundingMode::HALF_UP);
        $variance = $receiptTotal->minus($sourceTotal)->toScale(8, RoundingMode::HALF_UP);
        if (! $variance->isZero()) {
            $blockers[] = ['code' => 'SOURCE_RECEIPT_COST_VARIANCE', 'message' => 'ต้นทุนรวมใบรับผลิตไม่เท่ากับต้นทุนใบเบิกต้นทาง ผลต่าง '.$variance->__toString()];
        }

        return ['ready' => $blockers === [], 'source_total' => $sourceTotal->__toString(), 'receipt_total' => $receiptTotal->toScale(8, RoundingMode::HALF_UP)->__toString(), 'variance' => $variance->__toString(), 'blockers' => $blockers];
    }

    private function receiptTotal(array $document): string
    {
        return collect($document['lines'] ?? [])->reduce(fn (BigDecimal $total, array $line): BigDecimal => $total->plus(BigDecimal::of((string) ($line['value'] ?? '0'))), BigDecimal::zero())->toScale(8, RoundingMode::HALF_UP)->__toString();
    }

    /** @return array<string, array{on_hand:string, inventory_value:string}> */
    private function snapshotBalances($lines): array
    {
        $snapshot = [];
        foreach ($lines as $line) {
            $key = $this->balanceKey((int) $line->warehouse_id, (int) $line->item_id, (int) $line->uom_id);
            if (isset($snapshot[$key])) {
                continue;
            }
            $balance = StockBalance::query()->where([
                'warehouse_id' => $line->warehouse_id,
                'item_id' => $line->item_id,
                'uom_id' => $line->uom_id,
            ])->first();
            $snapshot[$key] = [
                'on_hand' => (string) ($balance?->on_hand ?? '0'),
                'inventory_value' => (string) ($balance?->inventory_value ?? '0'),
            ];
        }

        return $snapshot;
    }

    private function postedReconciliation($document, JournalEntry $journal, array $beforeBalances, array $movements, array $allocations, int $inventoryAccountId): array
    {
        $allocationExact = BigDecimal::zero();
        $allocationAccounting = BigDecimal::zero();
        $expectedQuantity = [];
        $actualQuantity = [];
        $unlinked = 0;
        $pending = 0;
        $mismatched = 0;

        foreach ($document->lines as $line) {
            $movement = $movements[$line->id] ?? null;
            $allocation = $allocations[$line->id] ?? null;
            if (! $movement || ! $allocation) {
                $unlinked++;

                continue;
            }
            // linkJournalLineWithinTransaction() locks and updates the
            // canonical allocation instance. Reload here so Gate C validates
            // persisted status/linkage, not the stale pre-link object.
            $allocation = CostAllocation::query()->with('journalLineLinks.journalEntryLine')->find($allocation->id);
            if (! $allocation) {
                $unlinked++;

                continue;
            }
            $value = BigDecimal::of((string) $allocation->value);
            $allocationExact = $allocationExact->plus($value);
            $allocationAccounting = $allocationAccounting->plus($value->toScale(2, RoundingMode::HALF_UP));
            $key = $this->balanceKey((int) $movement->warehouse_id, (int) $movement->item_id, (int) $movement->uom_id);
            $expectedQuantity[$key] = ($expectedQuantity[$key] ?? BigDecimal::zero())->plus(BigDecimal::of((string) $movement->base_quantity));
            $actualQuantity[$key] = BigDecimal::of((string) ($this->freshBalance($movement)?->on_hand ?? '0'));
            if ($allocation->status !== 'POSTED' || $allocation->cost_status !== 'FINAL') {
                $pending++;
            }
            $links = $allocation->journalLineLinks;
            if ($links->count() !== 1 || $allocation->journal_entry_id !== $journal->id || (int) ($links->first()?->journalEntryLine?->journal_entry_id ?? 0) !== (int) $journal->id) {
                $mismatched++;
            }
        }

        $balanceValueDelta = BigDecimal::zero();
        $quantityMismatch = [];
        foreach ($expectedQuantity as $key => $expected) {
            [$warehouseId, $itemId, $uomId] = array_map('intval', explode(':', $key));
            $after = StockBalance::query()
                ->where('warehouse_id', $warehouseId)->where('item_id', $itemId)->where('uom_id', $uomId)->first();
            $before = $beforeBalances[$key] ?? ['on_hand' => '0', 'inventory_value' => '0'];
            $actualOnHand = BigDecimal::of((string) ($after?->on_hand ?? '0'));
            $actualQuantity[$key] = $actualOnHand;
            $beforeOnHand = BigDecimal::of($before['on_hand']);
            if (! $actualOnHand->minus($beforeOnHand)->toScale(8, RoundingMode::HALF_UP)->isEqualTo($expected->toScale(8, RoundingMode::HALF_UP))) {
                $quantityMismatch[] = $key;
            }
            $balanceValueDelta = $balanceValueDelta->plus(BigDecimal::of((string) ($after?->inventory_value ?? '0'))->minus(BigDecimal::of($before['inventory_value'])));
        }

        $journalInventoryDebit = $journal->lines->where('account_id', $inventoryAccountId)->reduce(
            fn (BigDecimal $sum, $line): BigDecimal => $sum->plus(BigDecimal::of((string) $line->debit)),
            BigDecimal::zero()
        );
        $journalDebit = $journal->lines->reduce(fn (BigDecimal $sum, $line): BigDecimal => $sum->plus(BigDecimal::of((string) $line->debit)), BigDecimal::zero());
        $journalCredit = $journal->lines->reduce(fn (BigDecimal $sum, $line): BigDecimal => $sum->plus(BigDecimal::of((string) $line->credit)), BigDecimal::zero());
        $totals = InventoryReconciliationCalculator::totals(
            $allocationAccounting->toScale(2, RoundingMode::HALF_UP)->__toString(),
            $balanceValueDelta->toScale(2, RoundingMode::HALF_UP)->__toString(),
            $journalInventoryDebit->toScale(2, RoundingMode::HALF_UP)->__toString(),
            $unlinked,
            $pending,
            '0',
            $unlinked,
            $mismatched + count($quantityMismatch),
            $journalDebit->minus($journalCredit)->toScale(2, RoundingMode::HALF_UP)->__toString(),
            $allocationAccounting->toScale(2, RoundingMode::HALF_UP)->__toString()
        );
        $gate = InventoryReconciliationGate::evaluate($totals);
        $blockers = array_map(fn (string $code): string => match ($code) {
            'allocation_vs_gl_zero' => 'Cost Allocation กับบัญชีสินค้าคงเหลือไม่ตรงกัน',
            'balance_vs_allocation_zero' => 'Stock Projection กับ Cost Allocation ไม่ตรงกัน',
            'no_unlinked_allocations' => 'มี Cost Allocation ที่ยังไม่เชื่อมโยง',
            'no_pending_allocations' => 'มี Cost Allocation ที่ยังไม่ Final/Posted',
            'no_unlinked_journal_lines' => 'มี Journal Line ที่ยังไม่เชื่อมโยงกับ Allocation',
            'no_mismatched_journal_lines' => 'พบความสัมพันธ์ Movement/Allocation/Journal ไม่ตรงกัน',
            'rounding_difference_zero' => 'ยอด Debit/Credit ของ Journal ไม่สมดุล',
            default => $code,
        }, $gate['blockers']);

        return ['ready' => $gate['ready'] && $quantityMismatch === [], 'totals' => $totals, 'blockers' => $quantityMismatch === [] ? $blockers : [...$blockers, 'Stock Projection จำนวนไม่เพิ่มขึ้นตาม Movement ที่ Post']];
    }

    private function freshBalance(StockMovement $movement): ?StockBalance
    {
        return StockBalance::query()->where('warehouse_id', $movement->warehouse_id)->where('item_id', $movement->item_id)->where('uom_id', $movement->uom_id)->first();
    }

    private function balanceKey(int $warehouseId, int $itemId, int $uomId): string
    {
        return $warehouseId.':'.$itemId.':'.$uomId;
    }
}
