<?php

namespace App\Modules\Wms\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Wms\Models\CostRevaluationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CostRevaluationApprovalService
{
    public function approve(CostRevaluationRun $run, User $actor, string $reason, ?CostRevaluationPreflightService $preflight = null): CostRevaluationRun
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลการอนุมัติอย่างน้อย 10 ตัวอักษร']);
        }
        $preflightResult = ($preflight ?: app(CostRevaluationPreflightService::class))->check($run);
        if (! $preflightResult['ready']) {
            throw ValidationException::withMessages(['preflight' => 'Preflight ไม่ผ่าน: '.implode(', ', $preflightResult['blockers'])]);
        }

        return DB::transaction(function () use ($run, $actor, $reason): CostRevaluationRun {
            $locked = CostRevaluationRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== 'PENDING_APPROVAL') {
                throw ValidationException::withMessages(['status' => 'Revaluation Run นี้ไม่อยู่ในสถานะรออนุมัติ']);
            }
            if (($locked->shadow_snapshot['variance_report']['status'] ?? null) !== 'READY_FOR_REVIEW') {
                throw ValidationException::withMessages(['status' => 'Shadow Snapshot ยังมี blocker ไม่สามารถอนุมัติได้']);
            }

            $locked->forceFill(['status' => 'APPROVED', 'approved_at' => now(), 'approved_by' => $actor->id])->save();
            $this->audit($actor, $locked, 'APPROVED', $reason);

            return $locked->fresh('deltas');
        }, 3);
    }

    public function reject(CostRevaluationRun $run, User $actor, string $reason): CostRevaluationRun
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลการปฏิเสธอย่างน้อย 10 ตัวอักษร']);
        }

        return DB::transaction(function () use ($run, $actor, $reason): CostRevaluationRun {
            $locked = CostRevaluationRun::query()->lockForUpdate()->findOrFail($run->id);
            if ($locked->status !== 'PENDING_APPROVAL') {
                throw ValidationException::withMessages(['status' => 'Revaluation Run นี้ไม่อยู่ในสถานะรออนุมัติ']);
            }

            $locked->forceFill(['status' => 'REJECTED', 'last_error' => trim($reason)])->save();
            $this->audit($actor, $locked, 'REJECTED', $reason);

            return $locked->fresh('deltas');
        }, 3);
    }

    private function audit(User $actor, CostRevaluationRun $run, string $status, string $reason): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => 'wms.cost_revaluation.'.$status,
            'subject_type' => $run->getMorphClass(),
            'subject_id' => $run->id,
            'old_values' => ['status' => 'PENDING_APPROVAL'],
            'new_values' => ['status' => $status, 'reason' => trim($reason), 'run_id' => $run->id],
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);
    }
}
