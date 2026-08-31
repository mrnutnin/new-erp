<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosPaymentMethodContractTest extends TestCase
{
    public function test_cash_bank_card_and_cheque_use_matching_gl_control_types(): void
    {
        $root = dirname(__DIR__, 2);
        $request = file_get_contents($root.'/app/Modules/Finance/Requests/SaveBankAccountRequest.php');
        $migration = file_get_contents($root.'/database/migrations/2026_08_28_212000_expand_finance_payment_method_types.php');

        self::assertStringContainsString('in:CASH,BANK,CREDIT_CARD,CHEQUE', $request);
        self::assertStringContainsString("['CASH', 'BANK', 'CREDIT_CARD', 'CHEQUE']", $request);
        self::assertStringContainsString("ENUM('CASH','BANK','CREDIT_CARD','CHEQUE')", $migration);
    }
}
