<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\CostRecalculationRequest;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockCostLayer;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\RecostCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StockRecostService
{
    public function __construct(private readonly InventoryCostAllocationService $allocations) {}

    public function createPending(StockMovement $movement, array $resolution): StockCostLayer
    {
        if ($movement->status !== 'POSTED' || $movement->direction !== 'OUT' || ($resolution['status'] ?? null) !== 'PENDING') {
            throw ValidationException::withMessages(['movement' => 'สร้าง provisional cost ได้เฉพาะ Posted issue ที่มีสถานะ PENDING']);
        }

        return DB::transaction(function () use ($movement, $resolution): StockCostLayer {
            $request = CostRecalculationRequest::firstOrCreate([
                'idempotency_key' => 'negative-stock:movement:'.$movement->id,
            ], [
                'warehouse_id' => $movement->warehouse_id,
                'item_id' => $movement->item_id,
                'trigger_movement_id' => $movement->id,
                'status' => 'PENDING',
            ]);

            return StockCostLayer::firstOrCreate(['source_movement_id' => $movement->id], [
                'warehouse_id' => $movement->warehouse_id,
                'item_id' => $movement->item_id,
                'uom_id' => $movement->uom_id,
                'original_quantity' => $resolution['shortfall_quantity'],
                'remaining_quantity' => $resolution['shortfall_quantity'],
                'unit_cost' => $resolution['unit_cost'],
                'method' => $resolution['method'],
                'cost_status' => 'PENDING',
                'recost_request_id' => $request->id,
                'cost_delta' => '0.00000000',
                'business_date' => $movement->business_date,
            ]);
        });
    }

    /** Return requests whose pending layers can be resolved by this receipt. */
    public function requestIdsForReceipt(StockMovement $receipt): array
    {
        return CostRecalculationRequest::query()
            ->whereHas('pendingLayers', fn ($query) => $query
                ->where('warehouse_id', $receipt->warehouse_id)
                ->where('item_id', $receipt->item_id)
                ->where('uom_id', $receipt->uom_id)
                ->where('remaining_quantity', '>', 0))
            ->pluck('id')->all();
    }

    public function markProcessingForReceipt(int $receiptId): void
    {
        $receipt = StockMovement::query()->find($receiptId);
        if (! $receipt) {
            return;
        }

        $this->forReceiptRequests($receipt, function (CostRecalculationRequest $request): void {
            $request->markProcessing();
        });
    }

    public function markFailedForReceipt(int $receiptId, \Throwable $exception): void
    {
        $receipt = StockMovement::query()->find($receiptId);
        if (! $receipt) {
            return;
        }

        $message = $exception->getMessage();
        $this->forReceiptRequests($receipt, function (CostRecalculationRequest $request) use ($message): void {
            $request->markFailed($message);
        });
    }

    /**
     * Keep receipt-triggered status updates bounded when a long-running item
     * has accumulated many provisional requests. The request rows are not
     * deleted or re-keyed, so chunkById preserves a stable traversal while
     * each model update changes only status/error fields.
     */
    private function forReceiptRequests(StockMovement $receipt, callable $callback): void
    {
        CostRecalculationRequest::query()
            ->whereHas('pendingLayers', fn ($query) => $query
                ->where('warehouse_id', $receipt->warehouse_id)
                ->where('item_id', $receipt->item_id)
                ->where('uom_id', $receipt->uom_id)
                ->where('remaining_quantity', '>', 0))
            ->chunkById(250, function ($requests) use ($callback): void {
                foreach ($requests as $request) {
                    $callback($request);
                }
            });
    }

    public function resolveFromReceipt(StockMovement $receipt, string $actualUnitCost): int
    {
        if ($receipt->status !== 'POSTED' || $receipt->direction !== 'IN') {
            throw ValidationException::withMessages(['movement' => 'Recost ต้องเริ่มจาก Posted receipt']);
        }
        if (! preg_match('/^\d+(?:\.\d{1,8})?$/', $actualUnitCost)) {
            throw ValidationException::withMessages(['unit_cost' => 'ต้นทุนรับเข้าต้องเป็นเลขทศนิยมไม่ติดลบ สูงสุด 8 ตำแหน่ง']);
        }
        BigDecimal::of($actualUnitCost)->toScale(8, RoundingMode::UNNECESSARY);

        return DB::transaction(function () use ($receipt, $actualUnitCost): int {
            $pending = StockCostLayer::query()->where('warehouse_id', $receipt->warehouse_id)->where('item_id', $receipt->item_id)->where('uom_id', $receipt->uom_id)->where('cost_status', 'PENDING')->where('remaining_quantity', '>', 0)->orderBy('business_date')->orderBy('id')->lockForUpdate()->get();
            if ($pending->isEmpty()) {
                return 0;
            }
            // The receipt already increased this balance. Applying only the
            // provisional-vs-actual delta brings the negative issue back to
            // the same valuation as if the real cost had been known earlier.
            $balance = StockBalance::query()->where('warehouse_id', $receipt->warehouse_id)->where('item_id', $receipt->item_id)->where('uom_id', $receipt->uom_id)->lockForUpdate()->first();
            if (! $balance) {
                throw ValidationException::withMessages(['stock' => 'ไม่พบยอดคงเหลือสำหรับ recost receipt']);
            }
            $balanceDelta = BigDecimal::zero();
            $remainingReceipt = (string) $receipt->base_quantity;
            $resolved = 0;
            foreach ($pending as $layer) {
                if (BigDecimal::of($remainingReceipt)->isZero()) {
                    break;
                }
                $result = RecostCalculator::resolve((string) $layer->remaining_quantity, $remainingReceipt, (string) $layer->unit_cost, $actualUnitCost);
                if ($result['quantity'] === '0.00000000') {
                    continue;
                }
                $take = BigDecimal::of($result['quantity']);
                $left = BigDecimal::of((string) $layer->remaining_quantity)->minus($take);
                $delta = BigDecimal::of((string) $layer->cost_delta)->plus(BigDecimal::of($result['cost_delta']));
                // RecostCalculator returns the COGS-side delta
                // (actual issue cost - provisional issue cost). Inventory is
                // the contra side, so its projection must move in the
                // opposite direction. Adding this value would make an
                // issue/receipt pair leave a false inventory balance.
                $balanceDelta = $balanceDelta->minus(BigDecimal::of($result['cost_delta']));
                $layer->forceFill([
                    'remaining_quantity' => $this->out($left),
                    'cost_delta' => $this->out($delta),
                    'resolved_by_movement_id' => $receipt->id,
                    'resolved_at' => $left->isZero() ? now() : null,
                    'cost_status' => $left->isZero() ? 'FINAL' : 'PENDING',
                ])->save();
                $this->allocations->recordRecost($receipt, $layer, $result['quantity'], $result['cost_delta'], (int) $layer->recost_request_id);
                if ($left->isZero() && $layer->recost_request_id) {
                    CostRecalculationRequest::query()->whereKey($layer->recost_request_id)->update(['status' => 'RESOLVED', 'resolved_at' => now()]);
                }
                $remainingReceipt = $this->out(BigDecimal::of($remainingReceipt)->minus($take));
                $resolved++;
            }

            if (! $balanceDelta->isZero()) {
                $newValue = BigDecimal::of((string) $balance->inventory_value)->plus($balanceDelta);
                $newOnHand = BigDecimal::of((string) $balance->on_hand);
                $newAverage = $newOnHand->isZero() ? BigDecimal::zero() : $newValue->dividedBy($newOnHand, 8, RoundingMode::HALF_UP);
                $balance->forceFill([
                    'inventory_value' => $this->out($newValue),
                    'average_unit_cost' => $this->out($newAverage),
                ])->save();
            }

            return $resolved;
        }, 3);
    }

    /**
     * Process one posted receipt. It is safe to call repeatedly: once all
     * pending layers are final, the method performs no writes.
     */
    public function processReceipt(int $receiptId, string $actualUnitCost): int
    {
        $receipt = StockMovement::query()->findOrFail($receiptId);

        return $this->resolveFromReceipt($receipt, $actualUnitCost);
    }

    private function out(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }
}
