<?php

namespace App\Modules\Wms\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostAllocationReview;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CostAllocationReviewService
{
    public function quarantine(CostAllocation $allocation, array $evidence, string $reason, User $actor, bool $confirmed = false): CostAllocationReview
    {
        if (! $confirmed || mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['review' => 'ต้องยืนยันและระบุเหตุผลอย่างน้อย 10 ตัวอักษร']);
        }
        if ((string) $allocation->status !== 'PENDING' || $allocation->journal_entry_id === null) {
            throw ValidationException::withMessages(['allocation' => 'รองรับเฉพาะ allocation PENDING ที่มี Journal link']);
        }
        if (count($evidence) < 3) {
            throw ValidationException::withMessages(['evidence' => 'หลักฐานไม่ครบ ต้องมี source, journal และ movement/reversal evidence']);
        }

        return DB::transaction(function () use ($allocation, $evidence, $reason, $actor): CostAllocationReview {
            $locked = CostAllocation::query()->lockForUpdate()->findOrFail($allocation->id);
            $payload = ['allocation_id' => $locked->id, 'revision' => $locked->revision, 'evidence' => $evidence];
            $hash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            $existing = CostAllocationReview::query()->where('allocation_id', $locked->id)->where('revision', $locked->revision)->lockForUpdate()->first();
            if ($existing) {
                if (! hash_equals($existing->evidence_hash, $hash)) {
                    throw ValidationException::withMessages(['evidence' => 'Review identity เดิมมีหลักฐานคนละชุด']);
                }

                return $existing;
            }

            $review = CostAllocationReview::query()->create(['allocation_id' => $locked->id, 'revision' => $locked->revision, 'status' => 'OPEN', 'proposed_state' => 'REVIEW_REQUIRED', 'evidence_hash' => $hash, 'reason' => trim($reason), 'actor_id' => $actor->id, 'evidence' => $evidence]);
            AuditLog::query()->create(['user_id' => $actor->id, 'action' => 'wms.cost_allocation.reviewed', 'subject_type' => $review->getMorphClass(), 'subject_id' => $review->id, 'old_values' => [], 'new_values' => ['allocation_id' => $locked->id, 'revision' => $locked->revision, 'status' => 'OPEN', 'proposed_state' => 'REVIEW_REQUIRED', 'evidence_hash' => $hash], 'ip_address' => null, 'user_agent' => 'cli:legacy-repair-report']);

            return $review;
        }, 3);
    }
}
