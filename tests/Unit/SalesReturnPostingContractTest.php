<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesReturnPostingContractTest extends TestCase
{
    public function test_financial_return_locks_source_blocks_over_return_and_posts_credit_note(): void
    {
        $source = file_get_contents(__DIR__.'/../../app/Modules/Pos/Services/SalesReturnPostingService.php');
        self::assertStringContainsString('lockForUpdate()', $source);
        self::assertStringContainsString('whereKeyNot($return->id)', $source);
        self::assertStringContainsString("'event_code' => 'sales_credit_note'", $source);
        self::assertStringContainsString('recordFromJournalLine', $source);
        self::assertStringContainsString('openItems->allocate', $source);
        self::assertStringContainsString('postCashRefund', $source);
        self::assertStringContainsString('refund_bank_account_id', $source);
        self::assertStringContainsString('SalesReturnInventoryPostingService', $source);
        self::assertStringContainsString('cogs_journal_entry_id', $source);
        self::assertStringContainsString('finance_advance_deposit_applications', $source);
        self::assertStringContainsString('exactScaled', $source);
        self::assertStringContainsString('originalRevenueMetadata', $source);
        self::assertStringContainsString("'source' => 'DOCUMENT'", $source);
    }
}
