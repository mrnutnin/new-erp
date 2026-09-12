<?php

namespace App\Modules\Wms\Jobs;

use App\Modules\Wms\Models\CostRevaluationRun;
use App\Modules\Wms\Services\CostRevaluationJournalPostingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class PostCostRevaluationJournal implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $uniqueFor = 86400;

    public function __construct(public readonly int $runId)
    {
        $this->onQueue((string) config('erp.inventory.revaluation_queue', 'cost-propagation'));
    }

    public function handle(CostRevaluationJournalPostingService $posting): void
    {
        $posting->post(CostRevaluationRun::query()->findOrFail($this->runId));
    }

    public function uniqueId(): string
    {
        return 'wms-cost-revaluation-gl:'.$this->runId;
    }
}
