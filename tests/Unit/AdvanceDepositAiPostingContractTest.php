<?php

namespace Tests\Unit;

use App\Modules\Finance\Models\AdvanceDeposit;
use PHPUnit\Framework\TestCase;

final class AdvanceDepositAiPostingContractTest extends TestCase
{
    public function test_ai_reuses_finance_advance_subledger_with_tenders_and_cash_wht_posting(): void
    {
        $migration = file_get_contents(__DIR__.'/../../database/migrations/2026_08_29_111000_add_ai_contract_to_finance_advance_deposits.php');
        $service = file_get_contents(__DIR__.'/../../app/Modules/Pos/Services/AdvanceDepositPostingService.php');

        self::assertSame('finance_advance_deposits', (new AdvanceDeposit)->getTable());
        self::assertContains('tax_treatment', (new AdvanceDeposit)->getFillable());
        self::assertContains('balance_amount', (new AdvanceDeposit)->getFillable());
        self::assertStringContainsString("Schema::create('finance_advance_deposit_tenders'", $migration);
        self::assertStringContainsString("'ADVANCE_DEPOSIT_AI'", $migration);
        self::assertStringContainsString("resolveForEvent('customer_advance', 'CUSTOMER_ADVANCE')", $service);
        self::assertStringContainsString("resolveForEvent('customer_advance', 'WHT_RECEIVABLE')", $service);
        self::assertStringContainsString("'event_code' => 'customer_advance'", $service);
        self::assertStringContainsString("'posting_metadata'", $service);
        self::assertStringContainsString("'status' => 'POSTED'", $service);
    }
}
