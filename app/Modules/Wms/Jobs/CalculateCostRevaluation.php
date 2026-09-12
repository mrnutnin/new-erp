<?php

namespace App\Modules\Wms\Jobs;

use App\Modules\Wms\Models\CostRevaluationRun;
use App\Modules\Wms\Services\CostRevaluationApplyService;
use App\Modules\Wms\Services\CostRevaluationBatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class CalculateCostRevaluation implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public int $uniqueFor = 86400;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue((string) config('erp.inventory.revaluation_queue', 'cost-propagation'));
    }

    public function handle(CostRevaluationApplyService $revaluations, CostRevaluationBatchService $batches): void
    {
        $run = $revaluations->calculateQueued(CostRevaluationRun::query()->findOrFail($this->runId));
        if ($run->batch_id) {
            $batches->sync($run->batch_id);
        }
        if ($run->status === 'WAITING_CONTINUATION') {
            self::dispatch($run->id)->afterCommit();
        }
    }

    public function uniqueId(): string
    {
        return 'wms-cost-revaluation-calculate:'.$this->runId;
    }
}
