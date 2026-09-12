<?php

namespace App\Modules\Wms\Jobs;

use App\Modules\Wms\Models\CostRevaluationRun;
use App\Modules\Wms\Services\CostRevaluationApplyService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;

/**
 * Async execution boundary. The actual mutation is intentionally closed until
 * Phase 3 approval/reconciliation implementation is complete.
 */
final class ApplyCostRevaluation implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 86400;

    public int $timeout = 60;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue((string) config('erp.inventory.revaluation_queue', 'cost-propagation'));
    }

    public function handle(CostRevaluationApplyService $apply): void
    {
        $run = CostRevaluationRun::query()->findOrFail($this->runId);
        if (! in_array($run->status, ['APPROVED', 'APPLYING', 'WAITING_CONTINUATION', 'FAILED_RETRYABLE'], true)) {
            return;
        }
        if (! config('erp.inventory.revaluation_apply_enabled', false)) {
            throw new RuntimeException('Cost Revaluation Apply feature gate ยังปิดอยู่');
        }
        $result = $apply->applyChunk($run);
        if ($result['busy']) {
            $this->release(5);

            return;
        }
        if ($result['has_more']) {
            self::dispatch($run->id)->afterCommit();
        }
    }

    public function uniqueId(): string
    {
        return 'wms-cost-revaluation:'.$this->runId;
    }
}
