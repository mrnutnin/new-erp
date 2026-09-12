<?php

namespace App\Modules\Wms\Services;

use App\Models\User;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostAllocationReview;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Closes a legacy review after Accounting confirms that no repair is needed. */
final class LegacyAllocationReviewDecisionService
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function approveNoAction(CostAllocationReview $review, User $actor, Request $request, string $reason): array
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'ต้องระบุเหตุผลอย่างน้อย 10 ตัวอักษร']);
        }

        return DB::transaction(function () use ($review, $actor, $request, $reason): array {
            $lockedReview = CostAllocationReview::query()->lockForUpdate()->findOrFail($review->id);
            if ($lockedReview->status !== 'OPEN') {
                throw ValidationException::withMessages(['review' => 'Review นี้ถูกดำเนินการแล้ว']);
            }

            $allocation = CostAllocation::query()->lockForUpdate()->findOrFail($lockedReview->allocation_id);
            $movement = $allocation->movement()->lockForUpdate()->firstOrFail();
            if ($movement->source_type !== 'ISSUE_RETURN') {
                throw ValidationException::withMessages(['review' => 'ปิดแบบไม่แก้ไขได้เฉพาะ Issue Return ที่ Accounting ตรวจสอบแล้ว']);
            }

            $document = IssueReturn::query()->find((int) $movement->source_id);
            if (! $document && $movement->source_reference) {
                $document = IssueReturn::query()
                    ->where('document_number', $movement->source_reference)
                    ->first();
            }
            $documentDate = $document?->document_date?->format('Y-m-d');
            if ($document?->status !== 'REVERSED' || ! $documentDate) {
                throw ValidationException::withMessages(['review' => 'เอกสารต้นทางต้องอยู่สถานะ REVERSED และต้องมี document_date']);
            }
            if ($movement->business_date?->format('Y-m-d') !== $documentDate) {
                throw ValidationException::withMessages(['review' => 'วันที่ Movement ยังไม่ตรงกับ document_date ต้องแก้วันที่ก่อน']);
            }

            $evidence = is_array($lockedReview->evidence) ? $lockedReview->evidence : [];
            $evidence['decision'] = [
                'contract' => 'legacy-review-decision-v1',
                'decision' => 'APPROVED_NO_ACTION',
                'approved_by' => $actor->id,
                'reason' => trim($reason),
                'document_status' => $document->status,
                'document_date' => $documentDate,
                'movement_date' => $movement->business_date?->format('Y-m-d'),
            ];

            $before = $lockedReview->toArray();
            $lockedReview->forceFill([
                'status' => 'RESOLVED',
                'proposed_state' => 'APPROVED_NO_ACTION',
                'reason' => trim($reason),
                'evidence' => $evidence,
            ])->save();
            $this->audit->record('wms.cost_allocation.legacy_review.approved_no_action', $lockedReview, $before, $lockedReview->fresh()->toArray(), $actor, $request);

            return ['review_id' => $lockedReview->id, 'status' => 'RESOLVED', 'decision' => 'APPROVED_NO_ACTION'];
        }, 3);
    }
}
