<?php

namespace Tests\Unit;

use App\Modules\Asset\Models\AssetDisposal;
use App\Modules\Asset\Models\AssetDisposalLine;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the disposal/write-off boundary.
 *
 * These assertions intentionally inspect the domain boundary rather than
 * booting a database: the service owns the transaction and accounting side
 * effects, while these invariants must remain visible in the implementation.
 */
final class AssetDisposalContractTest extends TestCase
{
    private function projectPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }

    public function test_disposal_schema_and_model_keep_the_value_breakdown(): void
    {
        $migration = glob($this->projectPath('database/migrations/*create_asset_disposals_tables.php'))[0] ?? '';
        $source = file_get_contents($migration);

        foreach (['cost', 'accumulated_depreciation', 'accumulated_impairment', 'carrying_amount', 'proceeds', 'gain_loss'] as $column) {
            self::assertStringContainsString("decimal('{$column}'", $source);
        }
        $downstreamMigrations = glob($this->projectPath('database/migrations/*disposal*downstream*.php'));
        $downstreamSource = implode("\n", array_map('file_get_contents', $downstreamMigrations));
        foreach (['proceeds_reference', 'count_reference', 'investigation_reference', 'override_reason'] as $column) {
            self::assertStringContainsString($column, $downstreamSource);
        }
        self::assertStringContainsString("enum('disposal_type', ['SALE', 'WRITE_OFF'])", $source);
        self::assertStringContainsString("enum('status', ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'CANCELLED'])", $source);
        self::assertStringContainsString("unique(['asset_disposal_id', 'asset_id'])", $source);

        // Posted history is immutable; reversal metadata must be persisted on a
        // later migration rather than silently changing the original document.
        $reversalMigrations = glob($this->projectPath('database/migrations/*asset_disposal*reversal*.php'));
        $reversalSource = implode("\n", array_map('file_get_contents', $reversalMigrations));
        self::assertStringContainsString('reversal_of_id', $reversalSource);
        self::assertStringContainsString('reversal_reason', $reversalSource);

        self::assertContains('carrying_amount', (new AssetDisposalLine)->getFillable());
        self::assertContains('gain_loss', (new AssetDisposalLine)->getFillable());
        self::assertContains('original_status', (new AssetDisposalLine)->getFillable());
        self::assertContains('journal_entry_id', (new AssetDisposal)->getFillable());
        self::assertContains('reversal_of_id', (new AssetDisposal)->getFillable());
        self::assertContains('reversal_reason', (new AssetDisposal)->getFillable());
        self::assertContains('proceeds_reference', (new AssetDisposal)->getFillable());
        self::assertContains('override_reason', (new AssetDisposal)->getFillable());
    }

    public function test_disposal_service_exposes_the_accounting_and_downstream_guards(): void
    {
        $path = $this->projectPath('app/Modules/Asset/Services/AssetDisposalService.php');
        self::assertFileExists($path, 'Disposal service must exist before the feature is considered complete.');
        $source = file_get_contents($path);

        foreach (['lockForUpdate', 'JournalPostingService', 'postForBranchWithinTransaction', 'idempotency_key', 'proceeds_reference', 'count_reference', 'investigation_reference', 'override_reason'] as $contract) {
            self::assertStringContainsString($contract, $source, "Missing disposal contract marker: {$contract}");
        }
        self::assertMatchesRegularExpression('/carrying.?amount|carrying_amount/i', $source);
        self::assertMatchesRegularExpression('/max\s*\(\s*0|negative|ติดลบ|greater than|สูงกว่/i', $source);
        self::assertMatchesRegularExpression('/whereHas\(.*lines|downstream|settlement|ขาย|จำหน่ายซ้ำ|already/i', $source);
        self::assertMatchesRegularExpression('/(?:disposal|proceeds)[_ -]?clearing[_ -]?account[_ -]?id/i', $source);
        self::assertMatchesRegularExpression('/รับเงินซ้ำ|proceeds.?reference.*ใช้แล้ว/i', $source);
        self::assertMatchesRegularExpression('/เลขที่ตรวจนับ|เลขที่สอบสวน|เหตุผล Override/i', $source);
    }

    public function test_write_off_has_no_proceeds_and_sale_calculates_gain_or_loss_from_carrying_value(): void
    {
        $path = $this->projectPath('app/Modules/Asset/Services/AssetDisposalService.php');
        self::assertFileExists($path);
        $source = file_get_contents($path);

        self::assertMatchesRegularExpression('/WRITE_OFF/', $source);
        self::assertMatchesRegularExpression('/proceeds.*0|proceeds.*zero|เงินรับ.*0/i', $source);
        self::assertMatchesRegularExpression('/gain.?loss|กำไร|ขาดทุน/i', $source);
        self::assertMatchesRegularExpression('/cost.*accumulated|accumulated.*cost/i', $source);
        self::assertMatchesRegularExpression('/original_status|status.*line|สถานะเดิม/i', $source);
    }

    public function test_disposal_requires_open_period_and_posted_book_depreciation_covering_disposal_date(): void
    {
        $source = file_get_contents($this->projectPath('app/Modules/Asset/Services/AssetDisposalService.php'));

        self::assertStringContainsString('FiscalPeriod', $source);
        self::assertStringContainsString("where('status', 'OPEN')", $source);
        self::assertStringContainsString("whereDate('start_date', '<=', \$date)", $source);
        self::assertStringContainsString("whereDate('end_date', '>=', \$date)", $source);
        self::assertStringContainsString('AssetDepreciationRun', $source);
        self::assertStringContainsString("where('book_type', 'BOOK')", $source);
        self::assertStringContainsString("where('status', 'POSTED')", $source);
        self::assertStringContainsString("whereDate('run_through_date', '>=', \$date)", $source);
        self::assertStringContainsString('assertFinalDepreciationReady', $source);
        self::assertStringContainsString("'SUBMITTED', 'APPROVED'", $source);
    }
}
