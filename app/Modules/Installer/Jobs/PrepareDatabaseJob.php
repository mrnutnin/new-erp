<?php

namespace App\Modules\Installer\Jobs;

use App\Modules\Installer\Services\DatabasePreparationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PrepareDatabaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly string $jobId) {}

    public function handle(DatabasePreparationService $preparation): void
    {
        set_time_limit(0);
        $preparation->prepare($this->jobId);
    }
}
