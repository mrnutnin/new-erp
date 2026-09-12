<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Services\CostAllocationReviewService;
use Illuminate\Validation\ValidationException;

/** Quarantines legacy POS stock-date mismatches without mutating immutable rows. */
final class PhysicalSaleMovementDateReviewService
{
    public function __construct(private readonly CostAllocationReviewService $reviews) {}

    /** @return list<array{allocation_id:int,review_id:int,status:string}> */
    public function quarantine(PhysicalSale $sale, User $actor): array
    {
        $sale = PhysicalSale::query()->findOrFail($sale->id);
        $movements = StockMovement::query()
            ->where('source_type', 'POS')->where('source_id', (string) $sale->id)
            ->where('warehouse_id', $sale->warehouse_id)->where('status', 'POSTED')
            ->orderBy('id')->get(['id', 'warehouse_id', 'item_id', 'uom_id', 'business_date', 'source_reference']);
        $expected = $sale->document_date->format('Y-m-d');
        $results = [];

        foreach ($movements as $movement) {
            if ($movement->business_date?->format('Y-m-d') === $expected) {
                continue;
            }

            $allocations = CostAllocation::query()
                ->where('stock_movement_id', $movement->id)->whereIn('status', ['PENDING', 'POSTED'])
                ->whereNotNull('journal_entry_id')->orderBy('id')->get();
            if ($allocations->isEmpty()) {
                throw ValidationException::withMessages(['allocation' => "Movement #{$movement->id} ไม่มี Allocation ที่มี Journal proof สำหรับกักกัน"]);
            }

            foreach ($allocations as $allocation) {
                $evidence = [
                    'contract' => 'pos-movement-date-review-v1',
                    'source' => ['type' => 'POS', 'id' => (int) $sale->id, 'document_number' => $sale->document_number, 'document_date' => $expected],
                    'movement' => ['id' => (int) $movement->id, 'business_date' => $movement->business_date?->format('Y-m-d'), 'warehouse_id' => (int) $movement->warehouse_id, 'item_id' => (int) $movement->item_id, 'source_reference' => $movement->source_reference],
                    'allocation' => ['id' => (int) $allocation->id, 'revision' => (int) $allocation->revision, 'status' => $allocation->status, 'business_date' => $allocation->business_date?->format('Y-m-d'), 'journal_entry_id' => (int) $allocation->journal_entry_id],
                    'resolution' => 'ACCOUNTING_REVIEW_REQUIRED; no stock, allocation or journal mutation performed',
                ];
                $review = $this->reviews->quarantine($allocation, $evidence, 'POS Movement date ไม่ตรงกับ document date ต้องให้ Accounting ตรวจสอบแนวทาง reversal/repost', $actor, true);
                $results[] = ['allocation_id' => (int) $allocation->id, 'review_id' => (int) $review->id, 'status' => (string) $review->status];
            }
        }

        return $results;
    }
}
