<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AssetPeriodCloseGateContractTest extends TestCase
{
    public function test_period_close_gate_blocks_unposted_asset_work_and_unlinked_journals(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Modules/Accounting/Support/PeriodCloseGate.php');

        self::assertStringContainsString('appendAssetFailures', $source);
        self::assertStringContainsString('asset_depreciation_runs', $source);
        self::assertStringContainsString('asset_impairments', $source);
        self::assertStringContainsString('asset_disposals', $source);
        self::assertStringContainsString("whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])", $source);
        self::assertStringContainsString("where('book_type', 'BOOK')", $source);
        self::assertStringContainsString("where('status', 'POSTED')->whereNull('journal_entry_id')", $source);
    }
}
