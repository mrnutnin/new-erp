<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Modules\Wms\Models\CostAllocation;

/** Quarantines posted stock/cost rows that have no immutable accounting proof. */
final class AccountingProofReviewService
{
    public function __construct(private readonly CostAllocationReviewService $reviews) {}

    /** @param list<int> $allocationIds @return list<array{allocation_id:int,review_id:int,status:string}> */
    public function quarantineMissingProof(array $allocationIds, User $actor): array
    {
        $results = [];
        foreach (CostAllocation::query()->with('movement')->whereIn('id', $allocationIds)->orderBy('id')->get() as $allocation) {
            $movement = $allocation->movement;
            $evidence = [
                'contract' => 'accounting-proof-review-v1',
                'journal_proof_missing' => $allocation->journal_entry_id === null,
                'source' => ['type' => $movement?->source_type, 'id' => $movement?->source_id, 'reference' => $movement?->source_reference],
                'movement' => ['id' => $movement?->id, 'status' => $movement?->status, 'business_date' => $movement?->business_date?->format('Y-m-d')],
                'allocation' => ['id' => $allocation->id, 'status' => $allocation->status, 'cost_status' => $allocation->cost_status, 'journal_entry_id' => $allocation->journal_entry_id],
                'resolution' => 'ACCOUNTING_REVIEW_REQUIRED; no stock, allocation or journal mutation performed',
            ];
            $review = $this->reviews->quarantine($allocation, $evidence, 'Stock/Cost พร้อมใช้งานแต่ยังไม่มี Journal proof ต้องให้ Accounting ตรวจสอบก่อน Revaluation', $actor, true);
            $results[] = ['allocation_id' => (int) $allocation->id, 'review_id' => (int) $review->id, 'status' => (string) $review->status];
        }

        return $results;
    }
}
