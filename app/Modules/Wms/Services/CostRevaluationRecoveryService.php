<?php

namespace App\Modules\Wms\Services;

use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Wms\Jobs\ApplyCostRevaluation;
use App\Modules\Wms\Jobs\CalculateCostRevaluation;
use App\Modules\Wms\Models\CostRevaluationRun;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CostRevaluationRecoveryService
{
    public function resume(CostRevaluationRun $run, User $actor, string $reason): CostRevaluationRun
    {
        $this->reason($reason);
        [$run, $stage] = DB::transaction(function () use ($run, $actor, $reason): array {
            $locked = CostRevaluationRun::query()->lockForUpdate()->findOrFail($run->id);
            $runtime = (array) $locked->runtime_checkpoint;
            $stage = $this->resumeStage($locked, $runtime);
            if ($stage === 'APPLY' && ! config('erp.inventory.revaluation_apply_enabled', false)) {
                throw ValidationException::withMessages(['status' => 'ยังไม่เปิดใช้การ Apply Revaluation กรุณาเปิด Feature Gate ก่อนส่งงานกลับเข้าคิว']);
            }
            $leaseSeconds = max(90, min((int) config('erp.inventory.revaluation_calculation_lease_seconds', 120), 600));
            if (in_array($locked->status, ['CALCULATING', 'APPLYING'], true) && $locked->heartbeat_at?->gt(now()->subSeconds($leaseSeconds))) {
                throw ValidationException::withMessages(['status' => 'Run ยังมี Worker ทำงานอยู่ กรุณารอให้ Lease หมดอายุก่อน']);
            }
            unset($runtime['calculation_lease'], $runtime['apply_lease']);
            $runtime['stage'] = $stage;
            $before = $locked->toArray();
            $locked->forceFill([
                'status' => 'WAITING_CONTINUATION', 'runtime_checkpoint' => $runtime,
                'last_error' => null, 'heartbeat_at' => now(),
            ])->save();
            $this->audit($actor, $locked, 'wms.cost_revaluation.resumed', $before, $reason);

            return [$locked, $stage];
        }, 3);

        ($stage === 'CALCULATION' ? CalculateCostRevaluation::dispatch($run->id) : ApplyCostRevaluation::dispatch($run->id))->afterCommit();
        if ($run->batch_id) {
            app(CostRevaluationBatchService::class)->sync((int) $run->batch_id);
        }

        return $run->fresh();
    }

    public function cancel(CostRevaluationRun $run, User $actor, string $reason): CostRevaluationRun
    {
        $this->reason($reason);
        $run = DB::transaction(function () use ($run, $actor, $reason): CostRevaluationRun {
            $locked = CostRevaluationRun::query()->lockForUpdate()->findOrFail($run->id);
            if (! in_array($locked->status, ['QUEUED', 'CALCULATING', 'WAITING_CONTINUATION', 'FAILED_RETRYABLE', 'REQUIRES_REVIEW', 'LIMIT_REACHED', 'PENDING_APPROVAL', 'APPROVED', 'APPLYING'], true)) {
                throw ValidationException::withMessages(['status' => 'สถานะปัจจุบันไม่สามารถยกเลิก Run ได้']);
            }
            if ($locked->deltas()->where('status', 'APPLIED')->exists()) {
                throw ValidationException::withMessages(['status' => 'Run เริ่มกระทบ Stock/Cost แล้ว ต้องใช้ Compensating Run แทนการยกเลิก']);
            }
            $before = $locked->toArray();
            $runtime = (array) $locked->runtime_checkpoint;
            unset($runtime['calculation_lease'], $runtime['apply_lease']);
            $runtime['stage'] = 'CANCELLED';
            $locked->forceFill(['status' => 'CANCELLED', 'runtime_checkpoint' => $runtime, 'last_error' => trim($reason), 'heartbeat_at' => now()])->save();
            $this->audit($actor, $locked, 'wms.cost_revaluation.cancelled', $before, $reason);

            return $locked;
        }, 3);

        if ($run->batch_id) {
            app(CostRevaluationBatchService::class)->sync((int) $run->batch_id);
        }

        return $run->fresh();
    }

    private function resumeStage(CostRevaluationRun $run, array $runtime): string
    {
        $stage = (string) ($runtime['stage'] ?? '');
        if (in_array($run->status, ['QUEUED', 'CALCULATING'], true)) {
            return 'CALCULATION';
        }
        if ($run->status === 'APPROVED') {
            return 'APPLY';
        }
        if (in_array($run->status, ['WAITING_CONTINUATION', 'FAILED_RETRYABLE', 'APPLYING'], true) && in_array($stage, ['CALCULATION', 'APPLY'], true)) {
            return $stage;
        }

        throw ValidationException::withMessages(['status' => 'Run นี้ต้องแก้ Blocker หรือสร้าง Trigger revision ใหม่ ไม่สามารถ Resume snapshot เดิมได้']);
    }

    private function reason(string $reason): void
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['reason' => 'กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร']);
        }
    }

    private function audit(User $actor, CostRevaluationRun $run, string $action, array $before, string $reason): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id, 'action' => $action,
            'subject_type' => $run->getMorphClass(), 'subject_id' => $run->id,
            'old_values' => $before,
            'new_values' => ['status' => $run->status, 'reason' => trim($reason), 'run_id' => $run->id],
            'ip_address' => request()->ip(), 'user_agent' => request()->userAgent(),
        ]);
    }
}
