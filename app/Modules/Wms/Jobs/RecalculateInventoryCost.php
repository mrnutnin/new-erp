<?php

namespace App\Modules\Wms\Jobs;

use App\Modules\Wms\Services\StockRecostService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class RecalculateInventoryCost implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 86400;

    public function __construct(public readonly int $receiptId, public readonly string $actualUnitCost) {}

    public function handle(StockRecostService $recost): void
    {
        $recost->markProcessingForReceipt($this->receiptId);

        try {
            $recost->processReceipt($this->receiptId, $this->actualUnitCost);
        } catch (Throwable $exception) {
            $recost->markFailedForReceipt($this->receiptId, $exception);
            throw $exception;
        }
    }

    public function failed(Throwable $exception): void
    {
        app(StockRecostService::class)->markFailedForReceipt($this->receiptId, $exception);
    }

    public function uniqueId(): string
    {
        return 'wms-recost:receipt:'.$this->receiptId.':cost:'.$this->actualUnitCost;
    }
}
