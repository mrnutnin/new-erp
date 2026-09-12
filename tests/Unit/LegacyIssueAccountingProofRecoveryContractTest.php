<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LegacyIssueAccountingProofRecoveryContractTest extends TestCase
{
    private function projectPath(string $path): string
    {
        return dirname(__DIR__, 2).'/'.$path;
    }

    public function test_legacy_recovery_reuses_issue_posting_pipeline_and_open_period_gate(): void
    {
        $service = file_get_contents($this->projectPath('app/Modules/Wms/Services/LegacyIssueAccountingProofRecoveryService.php'));
        $posting = file_get_contents($this->projectPath('app/Modules/Wms/Services/IssueAccountingPostingService.php'));

        self::assertStringContainsString('class LegacyIssueAccountingProofRecoveryService', $service);
        self::assertStringContainsString('IssueAccountingPostingService', $service);
        self::assertStringContainsString("'POSTED'", $service);
        self::assertStringContainsString("'POSTING_PERIOD_NOT_OPEN'", $service);
        self::assertStringContainsString("'ACCOUNT_MAPPING_NOT_READY:'", $service);
        self::assertStringContainsString("'LEGACY_ORIGINAL_PROOF'", $service);
        self::assertStringContainsString("where('start_date', '<=', \$postingDate)", $service);
        self::assertStringContainsString("where('end_date', '>=', \$postingDate)", $service);
        self::assertStringContainsString("'entry_date' => \$date", $posting);
        self::assertStringContainsString("'document_date' => \$documentDate", $posting);
        self::assertStringContainsString('array_merge([', $posting);
    }

    public function test_recovery_does_not_backfill_journal_with_direct_sql(): void
    {
        $service = file_get_contents($this->projectPath('app/Modules/Wms/Services/LegacyIssueAccountingProofRecoveryService.php'));

        self::assertStringNotContainsString('DB::statement', $service);
        self::assertStringNotContainsString('JournalEntry::create', $service);
        self::assertStringContainsString('DB::transaction(function', $service);
        self::assertStringContainsString('lockForUpdate()', $service);
    }
}
