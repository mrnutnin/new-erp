<?php

namespace Tests\Unit;

use Tests\TestCase;

final class RecostSchedulerContractTest extends TestCase
{
    public function test_recost_dispatcher_is_scheduled_with_bounded_single_server_guards(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));

        $this->assertStringContainsString('new DispatchPendingInventoryRecost(100)', $source);
        $this->assertStringContainsString('->everyFiveMinutes()', $source);
        $this->assertStringContainsString('->withoutOverlapping()', $source);
        $this->assertStringContainsString('->onOneServer()', $source);
    }

    public function test_dispatcher_keeps_a_bounded_batch_limit(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Jobs/DispatchPendingInventoryRecost.php'));

        $this->assertStringContainsString('min($this->batchSize, 500)', $source);
        $this->assertStringContainsString('->limit($size)', $source);
    }
}
