<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class OperationalPeriodCloseGateContractTest extends TestCase
{
    public function test_period_close_blocks_unposted_live_gl_documents_and_missing_journals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../app/Modules/Accounting/Support/PeriodCloseGate.php');

        self::assertStringContainsString('appendOperationalPostingFailures', $source);
        self::assertStringContainsString('asset_capitalizations', $source);
        self::assertStringContainsString('sales_documents', $source);
        self::assertStringContainsString('pos_physical_sales', $source);
        self::assertStringContainsString('finance_settlements', $source);
        self::assertStringContainsString('pos_sales_commission_payout_batches', $source);
        self::assertStringContainsString('purchase_documents', $source);
        self::assertStringContainsString('wms_inventory_adjustment_documents', $source);
        self::assertStringContainsString("where('status', 'POSTED')->whereNull('journal_entry_id')", $source);
        self::assertStringContainsString("Schema::hasColumn(\$table, 'deleted_at')", $source);
    }
}
