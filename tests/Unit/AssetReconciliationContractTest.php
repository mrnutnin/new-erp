<?php

namespace Tests\Unit;

use App\Modules\Asset\Services\AssetReconciliationService;
use PHPUnit\Framework\TestCase;

final class AssetReconciliationContractTest extends TestCase
{
    public function test_reconciliation_reads_events_and_posted_gl_but_keeps_opening_separate(): void
    {
        $service = file_get_contents((new \ReflectionClass(AssetReconciliationService::class))->getFileName());

        self::assertStringContainsString('asset_value_events as events', $service);
        self::assertStringContainsString("events.event_type = 'OPENING'", $service);
        self::assertStringContainsString("entries.status', ['POSTED', 'REVERSED']", $service);
        self::assertStringContainsString('asset_depreciation_lines as depreciation_lines', $service);
    }
}
