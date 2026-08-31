<?php

namespace App\Modules\Wms\Jobs;

use App\Modules\Wms\Models\StockMovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Safety-net dispatcher for trusted receipts that can resolve pending cost.
 * It only enqueues bounded, per-receipt jobs; recost itself stays transactional
 * in StockRecostService. The scheduler may call this job after commit.
 */
final class DispatchPendingInventoryRecost implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(public readonly int $batchSize = 100) {}

    public function handle(): void
    {
        $size = max(1, min($this->batchSize, 500));

        StockMovement::query()
            ->where('status', 'POSTED')
            ->where('direction', 'IN')
            ->where('metadata->unit_cost_trusted', true)
            ->whereNotNull('metadata->unit_cost')
            ->whereExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('wms_stock_cost_layers as pending_layers')
                    ->join('wms_cost_recalculation_requests as requests', 'requests.id', '=', 'pending_layers.recost_request_id')
                    ->whereColumn('pending_layers.warehouse_id', 'wms_stock_movements.warehouse_id')
                    ->whereColumn('pending_layers.item_id', 'wms_stock_movements.item_id')
                    ->whereColumn('pending_layers.uom_id', 'wms_stock_movements.uom_id')
                    ->whereColumn('wms_stock_movements.business_date', '>=', 'pending_layers.business_date')
                    ->where('pending_layers.cost_status', 'PENDING')
                    ->where('pending_layers.remaining_quantity', '>', 0)
                    ->whereIn('requests.status', ['PENDING', 'PROCESSING']);
            })
            ->orderBy('id')
            ->limit($size)
            ->get(['id', 'metadata'])
            ->each(function (StockMovement $receipt): void {
                $metadata = is_array($receipt->metadata) ? $receipt->metadata : [];
                $unitCost = $metadata['unit_cost'] ?? null;
                if (is_string($unitCost) && $unitCost !== '') {
                    RecalculateInventoryCost::dispatch($receipt->id, $unitCost);
                }
            });
    }

    public function uniqueId(): string
    {
        return 'wms-recost:pending-dispatch';
    }
}
