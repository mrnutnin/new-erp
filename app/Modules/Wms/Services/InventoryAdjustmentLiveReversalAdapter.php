<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\InventoryAdjustment;
use App\Modules\Wms\Models\StockMovement;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Immutable correction boundary for a Posted Inventory Adjustment.
 * The original movement, allocation and Journal are never edited; one new
 * reversal revision is created and linked back to the source adjustment.
 */
final class InventoryAdjustmentLiveReversalAdapter
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly StockMovementService $movements,
        private readonly InventoryCostAllocationService $allocations,
        private readonly AuditLogger $audit,
    ) {}

    public function reverse(InventoryAdjustment $adjustment, string $date, string $reason, User $actor, Request $request, bool $featureEnabled = false): InventoryAdjustment
    {
        if (! $featureEnabled || ! config('erp.inventory.adjustment_posting_enabled', false)) {
            throw ValidationException::withMessages(['reversal' => 'Inventory Adjustment reversal ยังไม่เปิดใช้งาน']);
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'ต้องระบุเหตุผลการกลับรายการอย่างน้อย 10 ตัวอักษร']);
        }

        return DB::transaction(function () use ($adjustment, $date, $reason, $actor, $request): InventoryAdjustment {
            $locked = InventoryAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            if ((int) $locked->warehouse_id !== (int) $request->attributes->get('selectedWarehouse')->id) {
                abort(404);
            }
            if ($locked->reversal_status === 'REVERSED') {
                $existing = $locked->reversalJournal;
                if (! $existing || $existing->status !== 'POSTED' || $existing->entry_date->format('Y-m-d') !== $date || $locked->reversal_reason !== $reason) {
                    throw ValidationException::withMessages(['reversal' => 'Reversal identity เดิมไม่ตรงกับคำขอใหม่']);
                }

                return $locked->fresh();
            }
            if ($locked->status !== 'POSTED' || ! $locked->stock_movement_id || ! $locked->cost_allocation_id) {
                throw ValidationException::withMessages(['status' => 'กลับรายการได้เฉพาะ Adjustment ที่ลงบัญชีแล้ว']);
            }

            $movement = StockMovement::query()->lockForUpdate()->findOrFail($locked->stock_movement_id);
            $allocation = CostAllocation::query()->lockForUpdate()->findOrFail($locked->cost_allocation_id);
            if ($movement->status !== 'POSTED' || $movement->movement_type !== 'ADJUSTMENT' || (int) $movement->warehouse_id !== (int) $locked->warehouse_id) {
                throw ValidationException::withMessages(['movement' => 'Movement ต้นทางไม่ใช่ Adjustment ที่ Posted ใน Warehouse เดียวกัน']);
            }
            if ($allocation->status !== 'POSTED' || (int) $allocation->stock_movement_id !== (int) $movement->id || ! $allocation->journal_entry_id) {
                throw ValidationException::withMessages(['allocation' => 'Cost Allocation ต้นทางไม่สมบูรณ์สำหรับการกลับรายการ']);
            }
            $journal = JournalEntry::query()->with('lines')->lockForUpdate()->findOrFail($allocation->journal_entry_id);
            if ($journal->status !== 'POSTED' || $journal->source_type !== 'INVENTORY' || ! str_starts_with((string) $journal->source_id, (string) $movement->source_id)) {
                throw ValidationException::withMessages(['journal' => 'Journal ต้นทางไม่ตรงกับ Adjustment']);
            }

            $revision = (int) $locked->reversal_revision + 1;
            $key = "reversal:adjustment:{$locked->id}:revision:{$revision}";
            $reversalJournal = $this->journals->reverseWithinTransaction($journal, [
                'source_type' => 'INVENTORY', 'source_id' => $key, 'reversal_date' => $date, 'reason' => $reason,
            ], $actor);
            $reversalMovement = $this->movements->reverseWithinTransaction($movement, [
                'idempotency_key' => $key.':movement', 'business_date' => $date, 'created_by' => $actor->id,
            ]);
            $reversalAllocation = CostAllocation::query()->where('stock_movement_id', $reversalMovement->id)->where('status', '!=', 'REVERSED')->lockForUpdate()->first();
            if (! $reversalAllocation) {
                throw ValidationException::withMessages(['allocation' => 'ไม่พบ Cost Allocation ของ Movement กลับรายการ']);
            }
            $reversalAllocation->forceFill([
                'parent_allocation_id' => $allocation->id,
                'cost_status' => $allocation->cost_status,
                'unit_cost' => $allocation->unit_cost,
                'value' => BigDecimal::of((string) $allocation->value)->negated()->toScale(8)->__toString(),
                'metadata' => [...(is_array($reversalAllocation->metadata) ? $reversalAllocation->metadata : []), 'reversal_of_adjustment_id' => $locked->id, 'reversal_of_allocation_id' => $allocation->id],
            ])->save();
            $inventoryLine = $reversalJournal->lines()
                ->where('subledger_type', 'ITEM')
                ->where('subledger_id', (string) $reversalAllocation->item_id)
                ->lockForUpdate()
                ->sole();
            $this->allocations->linkJournalLineWithinTransaction($reversalAllocation, $inventoryLine);

            $before = $locked->toArray();
            $locked->forceFill([
                'reversal_status' => 'REVERSED', 'reversal_journal_entry_id' => $reversalJournal->id,
                'reversal_movement_id' => $reversalMovement->id, 'reversal_allocation_id' => $reversalAllocation->id,
                'reversed_by' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $reason, 'reversal_revision' => $revision,
            ])->save();
            $this->audit->record('wms.inventory_adjustment.reversed', $locked, $before, $locked->fresh()->toArray(), $actor, $request);

            return $locked->fresh();
        }, 3);
    }
}
