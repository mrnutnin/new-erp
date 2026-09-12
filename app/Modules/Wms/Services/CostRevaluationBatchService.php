<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\CostRevaluationBatch;
use Illuminate\Support\Facades\DB;

final class CostRevaluationBatchService
{
    public function sync(int $batchId): CostRevaluationBatch
    {
        return DB::transaction(function () use ($batchId): CostRevaluationBatch {
            $batch = CostRevaluationBatch::query()->lockForUpdate()->findOrFail($batchId);
            $runs = $batch->runs()->get(['id', 'status', 'expected_partitions', 'completed_partitions', 'failed_partitions']);
            $failed = $runs->sum('failed_partitions');
            $badStatuses = ['REQUIRES_REVIEW', 'LIMIT_REACHED', 'FAILED_RETRYABLE', 'REJECTED', 'CANCELLED'];
            $allCalculated = $runs->isNotEmpty() && $runs->every(fn ($run): bool => in_array($run->status, ['PENDING_APPROVAL', 'APPROVED', 'STOCK_PROJECTED', 'JOURNAL_POSTED', 'COMPLETED'], true));
            $scopePlanning = $batch->source_document_type === 'MANUAL_SCOPE'
                && ! data_get($batch->trigger_snapshot, 'scope.planning_complete', false);

            $batch->forceFill([
                'status' => $scopePlanning
                    ? 'PLANNING'
                    : ($runs->contains(fn ($run): bool => in_array($run->status, $badStatuses, true))
                    ? 'REQUIRES_REVIEW'
                    : ($allCalculated ? 'PENDING_APPROVAL' : 'CALCULATING')),
                'expected_partitions' => $scopePlanning ? max((int) $batch->expected_partitions, $runs->sum('expected_partitions')) : $runs->sum('expected_partitions'),
                'completed_partitions' => $runs->sum('completed_partitions'),
                'failed_partitions' => $failed,
                'heartbeat_at' => now(),
            ])->save();

            return $batch;
        }, 3);
    }
}
