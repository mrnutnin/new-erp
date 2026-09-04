<?php

namespace App\Modules\Purchasing\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostRecalculationRequest;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Services\InventoryCostAllocationService;
use App\Modules\Wms\Services\RecostGlPostingService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LandedCostPostingService
{
    public function __construct(
        private readonly InventoryCostAllocationService $allocations,
        private readonly RecostGlPostingService $recostGl,
    ) {}

    /**
     * Post approved allocations into the existing WMS RECOST/GL boundary.
     * The caller supplies release-gate evidence because reconciliation is an
     * operational decision, not something this writer may guess.
     */
    public function postApproved(LandedCost $landedCost, array $gate, ?User $actor = null): LandedCost
    {
        return DB::transaction(function () use ($landedCost, $gate, $actor): LandedCost {
            $locked = LandedCost::query()->with(['allocations.line', 'allocations.goodsReceiptLine.goodsReceipt'])->lockForUpdate()->findOrFail($landedCost->id);
            if ($locked->status === 'POSTED') {
                return $locked->fresh(['lines', 'receipts', 'allocations']);
            }
            if ($locked->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => 'Post Landed Cost ได้เฉพาะเอกสารที่ Approved']);
            }
            if (($gate['period_open'] ?? false) !== true || ($gate['reconciliation_ready'] ?? false) !== true) {
                throw ValidationException::withMessages(['gate' => 'ต้องผ่านงวดบัญชีเปิดและ reconciliation ก่อน Post Landed Cost']);
            }

            $warehouse = Warehouse::query()->with('branch')->findOrFail($locked->warehouse_id);
            foreach ($locked->allocations as $allocation) {
                $wmsAllocation = $this->ensureWmsAllocation($locked, $allocation);
                $this->recostGl->post($wmsAllocation, $warehouse, $gate, $actor);
                $allocation->forceFill(['wms_cost_allocation_id' => $wmsAllocation->id, 'status' => 'POSTED'])->save();
            }

            $locked->forceFill(['status' => 'POSTED', 'posted_at' => now(), 'posted_by' => $actor?->id, 'updated_by' => $actor?->id])->save();

            return $locked->fresh(['lines', 'receipts', 'allocations']);
        }, 3);
    }

    private function ensureWmsAllocation(LandedCost $landedCost, $allocation): CostAllocation
    {
        if ($allocation->wms_cost_allocation_id) {
            return CostAllocation::query()->lockForUpdate()->findOrFail($allocation->wms_cost_allocation_id);
        }

        $receiptLine = $allocation->goodsReceiptLine;
        $movement = StockMovement::query()
            ->where('source_type', 'GOODS_RECEIPT')
            ->where('source_id', $receiptLine->goods_receipt_id)
            ->where('status', 'POSTED')
            ->whereJsonContains('metadata->goods_receipt_line_id', (int) $receiptLine->id)
            ->lockForUpdate()->first();
        if (! $movement) {
            throw ValidationException::withMessages(['allocations' => "ไม่พบ Posted Stock Movement ของ Goods Receipt line #{$receiptLine->id}"]);
        }

        $source = CostAllocation::query()->where('stock_movement_id', $movement->id)->where('allocation_type', 'RECEIPT')->where('cost_status', 'FINAL')->where('status', '!=', 'REVERSED')->lockForUpdate()->first();
        if (! $source) {
            throw ValidationException::withMessages(['allocations' => "ไม่พบต้นทุนต้นทางของ Stock Movement #{$movement->id}"]);
        }

        $request = CostRecalculationRequest::query()->firstOrCreate([
            'idempotency_key' => "landed-cost:{$landedCost->id}:movement:{$movement->id}",
        ], [
            'warehouse_id' => $movement->warehouse_id,
            'item_id' => $movement->item_id,
            'trigger_movement_id' => $movement->id,
            'status' => 'PENDING',
        ]);
        $delta = BigDecimal::of((string) $allocation->allocated_amount)->toScale(8)->__toString();
        $wmsAllocation = $this->allocations->record($movement, (string) $source->method, [
            'allocation_type' => 'RECOST',
            'direction' => 'IN',
            'quantity' => (string) $source->quantity,
            'unit_cost' => BigDecimal::of($delta)->dividedBy((string) $source->quantity, 8)->__toString(),
            'value' => $delta,
            'cost_status' => 'FINAL',
            'stock_cost_layer_id' => $source->stock_cost_layer_id,
            'parent_allocation_id' => $source->id,
            'recost_request_id' => $request->id,
            'idempotency_key' => "landed-cost:{$landedCost->id}:allocation:{$allocation->id}",
            'metadata' => ['landed_cost_id' => $landedCost->id, 'landed_cost_allocation_id' => $allocation->id, 'source_allocation_id' => $source->id],
        ], (int) $request->id);

        return $wmsAllocation;
    }
}
