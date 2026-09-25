<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Models\StockReservation;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Reverses the immutable Stock/Cost/GL chain created by a Finished Receipt. */
final class ProductionFinishedReceiptReversalService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly StockMovementService $movements,
        private readonly InventoryCostAllocationService $allocations,
        private readonly StockReservationService $reservations,
        private readonly AuditLogger $audit,
        private readonly CostPropagationTriggerDispatcher $costPropagation,
    ) {}

    public function reverse(InventoryAdjustmentDocument $document, string $date, string $reason, User $actor, Request $request): InventoryAdjustmentDocument
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) throw ValidationException::withMessages(['reason' => 'ต้องระบุเหตุผลการกลับรายการอย่างน้อย 10 ตัวอักษร']);

        return DB::transaction(function () use ($document, $date, $reason, $actor, $request): InventoryAdjustmentDocument {
            $locked = InventoryAdjustmentDocument::query()->with('lines')->lockForUpdate()->findOrFail($document->id);
            if ((int) $locked->warehouse_id !== (int) $request->attributes->get('selectedWarehouse')->id) abort(404);
            if ($locked->document_context !== 'PRODUCTION_RECEIPT') throw ValidationException::withMessages(['document' => 'เอกสารนี้ไม่ใช่ใบรับสินค้าผลิตเสร็จ']);
            if ($locked->reversal_status === 'REVERSED') {
                if ($locked->reversal_reason !== $reason || $locked->reversed_at?->format('Y-m-d') !== $date) throw ValidationException::withMessages(['reversal' => 'Reversal identity เดิมไม่ตรงกับคำขอใหม่']);
                return $locked->fresh('lines');
            }
            if ($locked->status !== 'POSTED') throw ValidationException::withMessages(['status' => 'กลับรายการได้เฉพาะใบรับผลิตที่ลงบัญชีแล้ว']);
            $lines = $locked->lines()->lockForUpdate()->get();
            if ($lines->isEmpty() || $lines->contains(fn ($line) => $line->status !== 'POSTED' || ! $line->stock_movement_id || ! $line->cost_allocation_id)) throw ValidationException::withMessages(['lines' => 'ใบรับผลิตต้องมี Movement และ Cost Allocation ที่ Posted ครบทุกบรรทัด']);

            $movements = $lines->map(fn ($line) => StockMovement::query()->lockForUpdate()->findOrFail($line->stock_movement_id));
            $allocations = $lines->map(fn ($line) => CostAllocation::query()->lockForUpdate()->findOrFail($line->cost_allocation_id));
            if ($movements->contains(fn ($movement) => $movement->status !== 'POSTED' || $movement->source_type !== 'WMS_PRODUCTION_RECEIPT' || (string) $movement->source_id !== (string) $locked->id || (int) $movement->warehouse_id !== (int) $locked->warehouse_id)) throw ValidationException::withMessages(['movement' => 'Movement ต้นทางไม่ใช่ Production Receipt ที่ Posted ใน Warehouse เดียวกัน']);
            if ($allocations->contains(fn ($allocation, $index) => $allocation->status !== 'POSTED' || (int) $allocation->stock_movement_id !== (int) $movements[$index]->id || ! $allocation->journal_entry_id)) throw ValidationException::withMessages(['allocation' => 'Cost Allocation ต้นทางไม่สมบูรณ์สำหรับการกลับรายการ']);

            $journalIds = $allocations->pluck('journal_entry_id')->unique()->values();
            if ($journalIds->count() !== 1) throw ValidationException::withMessages(['journal' => 'ใบรับผลิตต้องมี Journal ต้นทางเพียงหนึ่งรายการ']);
            $journal = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($journalIds->first());
            if ($journal->status !== 'POSTED' || $journal->source_type !== 'WMS_PRODUCTION_RECEIPT' || $journal->source_event !== 'production.finished_receipt' || (string) $journal->source_id !== (string) $locked->id || (int) $journal->warehouse_id !== (int) $locked->warehouse_id) throw ValidationException::withMessages(['journal' => 'Journal ต้นทางไม่ตรงกับ Production Receipt']);

            $this->assertNoDownstreamFinishedGoodsUse($movements);
            $this->releaseFinishedGoodsReservation($locked);
            $this->assertFinishedGoodsAvailable($movements);
            $revision = (int) $locked->reversal_revision + 1;
            $key = "reversal:production-finished-receipt:{$locked->id}:revision:{$revision}";
            $reversalJournal = $this->journals->reverseWithinTransaction($journal, ['source_type' => 'WMS_PRODUCTION_RECEIPT', 'source_id' => $key, 'reversal_date' => $date, 'reason' => $reason], $actor);
            $reversalJournalLines = $reversalJournal->lines()->where('subledger_type', 'ITEM')->orderBy('line_number')->lockForUpdate()->get();
            if ($reversalJournalLines->count() !== $lines->count()) throw ValidationException::withMessages(['journal' => 'Journal กลับรายการมีจำนวนบรรทัดสินค้าไม่ตรงกับใบรับผลิต']);

            foreach ($lines as $index => $line) {
                $sourceMovement = $movements[$index];
                $balanceBeforeReversal = StockBalance::query()->where([
                    'warehouse_id' => $sourceMovement->warehouse_id,
                    'item_id' => $sourceMovement->item_id,
                    'uom_id' => $sourceMovement->uom_id,
                ])->lockForUpdate()->first();
                $reversalMovement = $this->movements->reverseWithinTransaction($movements[$index], ['idempotency_key' => $key.':line:'.$line->id.':movement', 'business_date' => $date, 'created_by' => $actor->id, 'parent_allocation_id' => $allocations[$index]->id]);
                $reversalAllocation = CostAllocation::query()->where('stock_movement_id', $reversalMovement->id)->where('status', '!=', 'REVERSED')->lockForUpdate()->first();
                if (! $reversalAllocation) throw ValidationException::withMessages(['allocation' => 'ไม่พบ Cost Allocation ของ Movement กลับรายการ']);
                $sourceAllocation = $allocations[$index];
                $reversalAllocation->forceFill(['parent_allocation_id' => $sourceAllocation->id, 'cost_status' => $sourceAllocation->cost_status, 'unit_cost' => $sourceAllocation->unit_cost, 'value' => (string) \Brick\Math\BigDecimal::of((string) $sourceAllocation->value)->negated()->toScale(8)->__toString(), 'metadata' => [...(is_array($reversalAllocation->metadata) ? $reversalAllocation->metadata : []), 'reversal_of_production_receipt_id' => $locked->id, 'reversal_of_allocation_id' => $sourceAllocation->id]])->save();
                $this->allocations->linkJournalLineWithinTransaction($reversalAllocation, $reversalJournalLines[$index]);
                // The reversal must unwind the original trusted receipt cost,
                // not the current average cost. Preserve the projection value
                // that existed immediately before this reversal and subtract
                // only the immutable source allocation value.
                if ($balanceBeforeReversal) {
                    $targetValue = \Brick\Math\BigDecimal::of((string) $balanceBeforeReversal->inventory_value)
                        ->minus(\Brick\Math\BigDecimal::of((string) $sourceAllocation->value));
                    $onHand = \Brick\Math\BigDecimal::of((string) $balanceBeforeReversal->on_hand)
                        ->minus(\Brick\Math\BigDecimal::of((string) $reversalMovement->base_quantity));
                    $average = $onHand->isPositive()
                        ? $targetValue->dividedBy($onHand, 8, \Brick\Math\RoundingMode::HALF_UP)
                        : \Brick\Math\BigDecimal::zero();
                    $balanceBeforeReversal->forceFill([
                        'inventory_value' => $targetValue->toScale(2, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
                        'average_unit_cost' => $average->toScale(8, \Brick\Math\RoundingMode::HALF_UP)->__toString(),
                    ])->save();
                }
                $line->forceFill([
                    'reversal_status' => 'REVERSED',
                    'reversal_journal_entry_id' => $reversalJournal->id,
                    'reversal_movement_id' => $reversalMovement->id,
                    'reversal_allocation_id' => $reversalAllocation->id,
                ])->save();
            }

            $before = $locked->toArray();
            $locked->forceFill(['status' => 'REVERSED', 'reversal_status' => 'REVERSED', 'reversed_by' => $actor->id, 'reversed_at' => CarbonImmutable::parse($date)->startOfDay(), 'reversal_reason' => $reason, 'reversal_revision' => $revision])->save();
            $this->syncProductionOrderAfterReversal($locked, $actor);
            $this->audit->record('wms.production_finished_receipt.reversed', $locked, $before, $locked->fresh('lines')->toArray(), $actor, $request);
            $this->costPropagation->dispatchIfEnabled('PRODUCTION_FINISHED_RECEIPT', $locked->id, $revision, [], $actor->id);
            return $locked->fresh('lines');
        }, 3);
    }

    private function assertNoDownstreamFinishedGoodsUse($movements): void
    {
        foreach ($movements as $movement) {
            // ponytail: block any later outbound of this pooled SKU; add lot-level attribution if this conservative guard causes false positives.
            StockBalance::query()->where([
                'warehouse_id' => $movement->warehouse_id,
                'item_id' => $movement->item_id,
                'uom_id' => $movement->uom_id,
            ])->lockForUpdate()->first();
            $usedLater = StockMovement::query()->where('warehouse_id', $movement->warehouse_id)
                ->where('item_id', $movement->item_id)->where('uom_id', $movement->uom_id)
                ->where('direction', 'OUT')->where('status', 'POSTED')
                ->where(fn ($query) => $query->where('created_at', '>', $movement->created_at)
                    ->orWhere(fn ($sameTime) => $sameTime->where('created_at', $movement->created_at)->where('id', '>', $movement->id)))
                ->exists();
            if ($usedLater) {
                throw ValidationException::withMessages(['status' => 'มีการเบิก/ขาย/เคลื่อนย้ายสินค้าสำเร็จรูปหลังรับผลิต จึงกลับรายการใบรับผลิตนี้ไม่ได้']);
            }
        }
    }

    private function assertFinishedGoodsAvailable($movements): void
    {
        $required = [];
        foreach ($movements as $movement) {
            $key = $movement->warehouse_id.':'.$movement->item_id.':'.$movement->uom_id;
            $required[$key]['movement'] = $movement;
            $required[$key]['quantity'] = ($required[$key]['quantity'] ?? BigDecimal::zero())->plus((string) $movement->base_quantity);
        }
        foreach ($required as $row) {
            $movement = $row['movement'];
            $balance = StockBalance::query()->where([
                'warehouse_id' => $movement->warehouse_id,
                'item_id' => $movement->item_id,
                'uom_id' => $movement->uom_id,
            ])->lockForUpdate()->first();
            if (! $balance || BigDecimal::of((string) $balance->available)->isLessThan($row['quantity'])) {
                throw ValidationException::withMessages(['stock' => 'สินค้าสำเร็จรูปคงเหลือพร้อมใช้ไม่พอสำหรับกลับรายการ']);
            }
        }
    }

    private function releaseFinishedGoodsReservation(InventoryAdjustmentDocument $receipt): void
    {
        $event = DB::table('production_order_events')->where('event_type', 'material_issue_created')->where('source_id', (string) $receipt->source_issue_id)->latest('id')->first();
        $order = $event ? DB::table('production_orders')->where('id', $event->production_order_id)->first() : null;
        if (! $order || $order->order_type !== 'MAKE_TO_ORDER' || ! (int) $order->sales_order_line_id) return;

        $reservation = StockReservation::query()
            ->where('source_type', StockReservation::SOURCE_SALES_ORDER_LINE)
            ->where('source_id', (string) $order->sales_order_line_id)
            ->where('idempotency_key', 'sales-order-line:'.$order->sales_order_line_id.':finished-goods')
            ->where('status', 'OPEN')
            ->lockForUpdate()
            ->first();
        if ($reservation) $this->reservations->release($reservation);
    }

    private function syncProductionOrderAfterReversal(InventoryAdjustmentDocument $receipt, User $actor): void
    {
        if ($receipt->document_context !== 'PRODUCTION_RECEIPT'
            || ! Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id')
            || ! Schema::hasTable('production_order_events')
            || ! Schema::hasTable('production_orders')) {
            return;
        }
        $event = DB::table('production_order_events')->where('event_type', 'material_issue_created')->where('source_id', (string) $receipt->source_issue_id)->latest('id')->first();
        if (! $event) return;
        $order = DB::table('production_orders')->where('id', $event->production_order_id)->lockForUpdate()->first();
        if (! $order || ! in_array($order->status, ['COMPLETED', 'IN_PROGRESS'], true)) return;

        $completed = DB::table('wms_inventory_adjustments')
            ->join('wms_inventory_adjustment_documents', 'wms_inventory_adjustment_documents.id', '=', 'wms_inventory_adjustments.document_id')
            ->where('wms_inventory_adjustment_documents.document_context', 'PRODUCTION_RECEIPT')
            ->where('wms_inventory_adjustment_documents.status', 'POSTED')
            ->where('wms_inventory_adjustment_documents.source_issue_id', $receipt->source_issue_id)
            ->where('wms_inventory_adjustments.item_id', $order->finished_item_id)
            ->where('wms_inventory_adjustments.status', 'POSTED')
            ->sum('wms_inventory_adjustments.quantity');
        $completed = \Brick\Math\BigDecimal::of((string) $completed)->toScale(8, \Brick\Math\RoundingMode::HALF_UP);
        $planned = \Brick\Math\BigDecimal::of((string) $order->planned_quantity)->toScale(8, \Brick\Math\RoundingMode::HALF_UP);
        $done = ! $completed->isLessThan($planned);

        DB::table('production_orders')->where('id', $order->id)->update([
            'status' => $done ? 'COMPLETED' : 'IN_PROGRESS',
            'completed_quantity' => $completed->__toString(),
            'completed_at' => $done ? now() : null,
            'completed_by' => $done ? $actor->id : null,
            'updated_by' => $actor->id,
            'updated_at' => now(),
        ]);
        if (! DB::table('production_order_events')->where('event_type', 'finished_receipt_reversed')->where('source_id', (string) $receipt->id)->exists()) {
            DB::table('production_order_events')->insert(['production_order_id' => $order->id, 'event_type' => 'finished_receipt_reversed', 'source_type' => InventoryAdjustmentDocument::class, 'source_id' => (string) $receipt->id, 'payload' => json_encode(['document_number' => $receipt->document_number, 'completed_quantity' => $completed->__toString(), 'completed' => $done]), 'occurred_at' => now(), 'created_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
