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
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\InventoryAdjustmentPostingContract;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Internal Adjustment -> GL adapter. Document posting invokes this service
 * inside one outer transaction; each line remains idempotent.
 * The feature flag is closed by default and the caller must provide the
 * already-approved, reconciled adjustment context.
 */
final class InventoryAdjustmentPostingService
{
    public function __construct(
        private readonly JournalPostingService $journals,
        private readonly AccountMappingService $mappings,
        private readonly InventoryCostPostingService $costPosting,
        private readonly InventoryCostAllocationService $allocations,
        private readonly StockBalanceProjectionService $balances,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Owns the complete APPROVED adjustment transaction. The HTTP layer must
     * not create stock movement/allocation rows itself; keeping this here
     * makes the idempotency and rollback boundary explicit.
     */
    public function postAdjustment(
        InventoryAdjustment $adjustment,
        Warehouse $warehouse,
        User $actor,
        Request $request,
    ): InventoryAdjustment {
        if (! config('erp.inventory.adjustment_posting_enabled', false)) {
            throw ValidationException::withMessages(['posting' => 'Inventory Adjustment → GL ยังไม่เปิดใช้งาน']);
        }

        return DB::transaction(function () use ($adjustment, $warehouse, $actor, $request): InventoryAdjustment {
            $locked = InventoryAdjustment::query()->lockForUpdate()->findOrFail($adjustment->id);
            if ($locked->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => 'ลงบัญชีได้เฉพาะรายการที่อนุมัติแล้ว']);
            }

            $movement = StockMovement::query()->firstOrCreate(
                ['idempotency_key' => $locked->idempotency_key.':movement'],
                [
                    'warehouse_id' => $locked->warehouse_id, 'item_id' => $locked->item_id, 'uom_id' => $locked->uom_id,
                    'movement_type' => 'ADJUSTMENT', 'direction' => $locked->direction === 'GAIN' ? 'IN' : 'OUT',
                    'status' => 'POSTED', 'quantity' => $locked->quantity, 'base_quantity' => $locked->quantity,
                    'business_date' => $locked->business_date, 'source_type' => 'INVENTORY',
                    'source_id' => 'adjustment:'.$locked->id, 'source_reference' => 'ADJ-'.$locked->id,
                    'metadata' => ['reason' => $locked->reason], 'posted_at' => now(), 'created_by' => $actor->id,
                ],
            );
            $allocation = CostAllocation::query()->firstOrCreate(
                ['idempotency_key' => $locked->idempotency_key.':allocation'],
                [
                    'stock_movement_id' => $movement->id, 'warehouse_id' => $locked->warehouse_id, 'item_id' => $locked->item_id,
                    'uom_id' => $locked->uom_id, 'allocation_type' => 'ADJUSTMENT',
                    'direction' => $locked->direction === 'GAIN' ? 'IN' : 'OUT', 'cost_status' => 'FINAL', 'status' => 'PENDING',
                    'method' => 'AVG', 'policy_version' => 'MVP', 'revision' => 0, 'quantity' => $locked->quantity,
                    'unit_cost' => bcdiv((string) $locked->value, (string) $locked->quantity, 8), 'value' => $locked->value,
                    'business_date' => $locked->business_date, 'metadata' => ['reason' => $locked->reason],
                ],
            );
            $posted = $this->post($allocation, $movement, $warehouse, $actor, $request, [
                'direction' => $locked->direction, 'reason' => $locked->reason, 'source_type' => 'INVENTORY',
                'source_id' => 'adjustment:'.$locked->id, 'source_reference' => 'ADJ-'.$locked->id,
                'warehouse_id' => $locked->warehouse_id, 'item_id' => $locked->item_id, 'uom_id' => $locked->uom_id,
                'business_date' => $locked->business_date->format('Y-m-d'), 'quantity' => (string) $locked->quantity,
                'value' => (string) $locked->value, 'idempotency_key' => $locked->idempotency_key.':allocation:'.$allocation->id,
                'approved' => true, 'period_open' => true, 'reconciled' => true,
            ], true);
            // Adjustment posting creates its immutable movement directly so
            // the document/GL transaction stays atomic. Rebuild the bounded
            // stock projection here as well; otherwise the movement ledger
            // says stock was added while StockBalance remains stale at zero,
            // causing a valid document reversal to be rejected incorrectly.
            $this->balances->rebuild((int) $locked->warehouse_id, (int) $locked->item_id, (int) $locked->uom_id);
            $before = $locked->toArray();
            $locked->forceFill(['status' => 'POSTED', 'stock_movement_id' => $movement->id, 'cost_allocation_id' => $posted->id])->save();
            $this->audit->record('wms.inventory_adjustment.posted', $locked, $before, $locked->fresh()->toArray(), $actor, $request);

            return $locked->fresh();
        }, 3);
    }

    public function post(
        CostAllocation $allocation,
        StockMovement $movement,
        Warehouse $warehouse,
        User $actor,
        Request $request,
        array $context,
        bool $featureEnabled = false,
    ): CostAllocation {
        if (! $featureEnabled || ! config('erp.inventory.adjustment_posting_enabled', false)) {
            throw ValidationException::withMessages(['posting' => 'Inventory Adjustment → GL ยังไม่เปิดใช้งาน']);
        }

        return DB::transaction(function () use ($allocation, $movement, $warehouse, $actor, $request, $context): CostAllocation {
            $lockedMovement = StockMovement::query()->lockForUpdate()->findOrFail($movement->id);
            $lockedAllocation = CostAllocation::query()->lockForUpdate()->findOrFail($allocation->id);
            $context['source_type'] = (string) ($context['source_type'] ?? $lockedMovement->source_type);
            $context['source_id'] = (string) ($context['source_id'] ?? $lockedMovement->source_id);
            if (! str_contains($context['source_id'], ':allocation:'.$lockedAllocation->id)) {
                $context['source_id'] .= ':allocation:'.$lockedAllocation->id;
            }
            $context['source_reference'] = (string) ($context['source_reference'] ?? $lockedMovement->source_reference);
            $context['warehouse_id'] = (int) ($context['warehouse_id'] ?? $lockedMovement->warehouse_id);
            $context['item_id'] = (int) ($context['item_id'] ?? $lockedMovement->item_id);
            $context['uom_id'] = (int) ($context['uom_id'] ?? $lockedMovement->uom_id);
            $context['business_date'] = (string) ($context['business_date'] ?? $lockedMovement->business_date?->format('Y-m-d'));
            $context['quantity'] = (string) ($context['quantity'] ?? $lockedAllocation->quantity);
            $context['value'] = (string) ($context['value'] ?? $lockedAllocation->value);
            $context['direction'] = strtoupper((string) ($context['direction'] ?? ($lockedMovement->direction === 'IN' ? 'GAIN' : 'LOSS')));
            $context['idempotency_key'] = (string) ($context['idempotency_key'] ?? $lockedMovement->idempotency_key);
            $context['approved'] = $context['approved'] ?? false;
            $context['period_open'] = $context['period_open'] ?? false;
            $context['reconciled'] = $context['reconciled'] ?? false;
            if (! str_contains($context['idempotency_key'], 'allocation:'.$lockedAllocation->id)) {
                throw ValidationException::withMessages(['idempotency_key' => 'Adjustment identity ต้องระบุ allocation เพื่อรองรับหลายบรรทัดอย่างปลอดภัย']);
            }
            // JournalPostingService owns the persisted posting fingerprint. Do
            // not compare its journal payload hash with the adjustment
            // contract hash: they intentionally cover different payloads.
            // Retry is verified by the same source identity at the Journal
            // boundary below, while changed reason/amount is rejected there.
            InventoryAdjustmentPostingContract::preview($context);

            if ((int) $lockedMovement->warehouse_id !== (int) $warehouse->id || (int) $lockedAllocation->warehouse_id !== (int) $warehouse->id) {
                throw ValidationException::withMessages(['warehouse_id' => 'Adjustment ไม่อยู่ใน Warehouse scope เดียวกัน']);
            }
            if ($lockedMovement->status !== 'POSTED' || $lockedMovement->movement_type !== 'ADJUSTMENT') {
                throw ValidationException::withMessages(['movement' => 'Adjustment Movement ต้อง POSTED และเป็น ADJUSTMENT ก่อนลงบัญชี']);
            }
            if ($lockedAllocation->stock_movement_id !== $lockedMovement->id || $lockedAllocation->allocation_type !== 'ADJUSTMENT' || $lockedAllocation->cost_status !== 'FINAL') {
                throw ValidationException::withMessages(['allocation' => 'Adjustment allocation ต้องเป็น FINAL และอ้าง Movement เดียวกัน']);
            }
            if ($lockedAllocation->journal_entry_id !== null) {
                $existing = JournalEntry::query()->whereKey($lockedAllocation->journal_entry_id)->lockForUpdate()->first();
                if (! $existing || $existing->status !== 'POSTED' || $existing->source_type !== $context['source_type']
                    || (string) $existing->source_id !== $context['source_id']
                    || $existing->description !== 'ปรับปรุงสินค้าคงเหลือ: '.$context['reason']) {
                    throw ValidationException::withMessages(['idempotency_key' => 'Adjustment identity เดิมไม่ตรงกับข้อมูลที่ขอ retry']);
                }

                return $lockedAllocation;
            }

            $preview = $this->costPosting->dryRun([$lockedAllocation], 'inventory.adjustment', $this->mappings);
            $lines = collect($preview['lines'])->map(fn (array $line): array => [
                'account_id' => $line['account_id'],
                'subledger_type' => $line['account_mapping'] === 'INVENTORY_DEFAULT' ? 'ITEM' : null,
                'subledger_id' => $line['account_mapping'] === 'INVENTORY_DEFAULT' ? (string) $lockedAllocation->item_id : null,
                'description' => 'Inventory Adjustment '.$context['direction'],
                'debit' => $line['side'] === 'DEBIT' ? $line['amount'] : '0.00',
                'credit' => $line['side'] === 'CREDIT' ? $line['amount'] : '0.00',
            ])->all();
            $journal = $this->journals->postWithinTransaction([
                'source_type' => $context['source_type'], 'source_id' => $context['source_id'],
                'event_code' => 'inventory_adjustment', 'entry_date' => $context['business_date'],
                'document_date' => $context['business_date'], 'source_reference' => $context['source_reference'],
                'description' => 'ปรับปรุงสินค้าคงเหลือ: '.$context['reason'], 'lines' => $lines,
            ], $warehouse, $actor);
            $inventoryAccountId = (int) collect($lines)
                ->firstWhere('subledger_type', 'ITEM')['account_id'];
            $inventoryLine = $journal->lines()
                ->where('account_id', $inventoryAccountId)
                ->where('subledger_type', 'ITEM')
                ->where('subledger_id', (string) $lockedAllocation->item_id)
                ->lockForUpdate()
                ->sole();
            $this->allocations->linkJournalLineWithinTransaction($lockedAllocation, $inventoryLine);
            $before = $lockedAllocation->only(['journal_entry_id', 'status']);
            $lockedAllocation->forceFill(['journal_entry_id' => $journal->id, 'status' => 'POSTED'])->save();
            $this->audit->record('wms.inventory_adjustment.posted', $lockedAllocation, $before, $lockedAllocation->only(['journal_entry_id', 'status']), $actor, $request);

            return $lockedAllocation->refresh();
        }, 3);
    }
}
