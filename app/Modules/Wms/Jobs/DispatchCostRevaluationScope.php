<?php

namespace App\Modules\Wms\Jobs;

use App\Modules\Wms\Services\CostPropagationScopeTriggerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DispatchCostRevaluationScope implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 86400;

    public function __construct(public readonly int $batchId)
    {
        $this->onQueue((string) config('erp.inventory.revaluation_queue', 'cost-propagation'));
    }

    public function handle(CostPropagationScopeTriggerService $scopes): void
    {
        if ($scopes->dispatchChunk($this->batchId)) {
            self::dispatch($this->batchId)->afterCommit();
        }
    }

    public function uniqueId(): string
    {
        return 'wms-cost-revaluation-scope:'.$this->batchId;
    }
}
