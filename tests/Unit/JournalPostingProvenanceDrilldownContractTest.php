<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class JournalPostingProvenanceDrilldownContractTest extends TestCase
{
    public function test_journal_detail_exposes_immutable_posting_provenance_and_source_drilldown(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = (string) file_get_contents($root.'/app/Modules/Accounting/Controllers/JournalEntryController.php');
        $view = (string) file_get_contents($root.'/app/Modules/Accounting/Views/journal-entries/show.blade.php');

        self::assertStringContainsString('sourceDocumentLink', $controller);
        self::assertStringContainsString('posSourceDocumentLink', $controller);
        self::assertStringContainsString("'asset.depreciation'", $controller);
        self::assertStringContainsString("'supplier_invoice.inventory'", $controller);
        self::assertStringContainsString("'customer_payment'", $controller);
        self::assertStringContainsString("'sales_commission_payout'", $controller);
        self::assertStringContainsString('posting_metadata', $view);
        self::assertStringContainsString('ที่มาของการลงบัญชี', $view);
        self::assertStringContainsString('Account role', $view);
        self::assertStringContainsString('mapping_version', $view);
        self::assertStringContainsString('reversalOf', $view);
        self::assertStringContainsString('reversal', $view);
    }
}
