<?php

namespace Tests\Unit;

use App\Modules\Asset\Services\AssetOpeningBalanceStagingService;
use PHPUnit\Framework\TestCase;

final class AssetOpeningBalanceStagingContractTest extends TestCase
{
    public function test_opening_source_identity_is_explicit(): void
    {
        self::assertSame('OPENING', AssetOpeningBalanceStagingService::SOURCE_TYPE);
    }

    public function test_staging_keeps_reconciliation_and_blocks_duplicate_commit_without_gl_posting(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_08_31_214000_create_asset_opening_balance_staging_tables.php');
        $service = file_get_contents((new \ReflectionClass(AssetOpeningBalanceStagingService::class))->getFileName());

        self::assertStringContainsString("'reconciliation_reference'", $migration);
        self::assertStringContainsString("'opening_accumulated_depreciation'", $migration);
        self::assertStringContainsString("'opening_accumulated_impairment'", $migration);
        self::assertStringContainsString("'source_type' => self::SOURCE_TYPE", $service);
        self::assertStringContainsString("'source_line_id' => \$line->id", $service);
        self::assertStringNotContainsString('JournalPostingService', $service);
    }
}
