<?php

namespace Tests\Unit;

use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Support\AdvanceDepositPostingContract;
use InvalidArgumentException;
use Tests\TestCase;

class AdvanceDepositPostingContractTest extends TestCase
{
    public function test_customer_source_is_bank_debit_and_liability_credit(): void
    {
        $lines = AdvanceDepositPostingContract::sourceLines('CUSTOMER', 10, 20, '100.00', 'advance');

        $this->assertSame(10, $lines[0]['account_id']);
        $this->assertSame('100.00', $lines[0]['debit']);
        $this->assertSame('100.00', $lines[1]['credit']);
        $this->assertSame('BANK', $lines[0]['subledger_type']);
        $this->assertBalanced($lines, 10000);
    }

    public function test_supplier_application_is_ap_debit_and_asset_credit(): void
    {
        $lines = AdvanceDepositPostingContract::applicationLines('SUPPLIER', 20, 30, 99, '40.00', 'apply');

        $this->assertSame(30, $lines[0]['account_id']);
        $this->assertSame('SUPPLIER', $lines[0]['subledger_type']);
        $this->assertSame('40.00', $lines[0]['debit']);
        $this->assertSame('40.00', $lines[1]['credit']);
        $this->assertBalanced($lines, 4000);
    }

    public function test_reversal_swaps_each_line_and_keeps_balance(): void
    {
        $lines = AdvanceDepositPostingContract::sourceLines('CUSTOMER', 10, 20, '100.00', 'advance');
        $reversal = AdvanceDepositPostingContract::reverseLines($lines);

        $this->assertSame($lines[0]['credit'], $reversal[0]['debit']);
        $this->assertSame($lines[1]['debit'], $reversal[1]['credit']);
        $this->assertBalanced($reversal, 10000);
    }

    public function test_invalid_direction_or_amount_fails_closed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AdvanceDepositPostingContract::sourceLines('CUSTOMER', 10, 20, '0.00', 'advance');
    }

    public function test_customer_advance_does_not_use_customer_payment_event(): void
    {
        $this->assertSame('customer_advance', AdvanceDepositPostingContract::event('CUSTOMER'));
        $this->assertNotSame('customer_payment', AdvanceDepositPostingContract::event('CUSTOMER'));
        $this->assertSame('supplier_payment', AdvanceDepositPostingContract::event('SUPPLIER'));
    }

    private function assertBalanced(array $lines, int $expectedCents): void
    {
        $totals = JournalBalance::totals($lines);

        $this->assertSame($expectedCents, $totals['debit']);
        $this->assertSame($totals['debit'], $totals['credit']);
    }
}
