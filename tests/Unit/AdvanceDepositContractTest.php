<?php

namespace Tests\Unit;

use App\Modules\Finance\Support\AdvanceDepositContract;
use InvalidArgumentException;
use Tests\TestCase;

class AdvanceDepositContractTest extends TestCase
{
    public function test_customer_receipt_and_supplier_payment_are_the_only_valid_directions(): void
    {
        AdvanceDepositContract::assertPartyDirection('CUSTOMER', 'RECEIPT');
        AdvanceDepositContract::assertPartyDirection('SUPPLIER', 'PAYMENT');

        $this->expectException(InvalidArgumentException::class);
        AdvanceDepositContract::assertPartyDirection('CUSTOMER', 'PAYMENT');
    }

    public function test_application_cannot_exceed_remaining_advance(): void
    {
        AdvanceDepositContract::assertApplicationAmount('100.00', '40.00', '60.00');

        $this->expectException(InvalidArgumentException::class);
        AdvanceDepositContract::assertApplicationAmount('100.00', '40.00', '60.01');
    }

    public function test_status_and_transitions_preserve_posted_history(): void
    {
        $this->assertSame('POSTED', AdvanceDepositContract::status('100.00', '0.00'));
        $this->assertSame('PARTIAL', AdvanceDepositContract::status('100.00', '25.00'));
        $this->assertSame('APPLIED', AdvanceDepositContract::status('100.00', '100.00'));
        $this->assertSame('REVERSED', AdvanceDepositContract::state('POSTED', 'REVERSE'));
    }

    public function test_direction_and_idempotency_boundary_is_not_an_ar_ap_allocation(): void
    {
        $this->assertSame('PARTIAL', AdvanceDepositContract::status('100.00', '25.00'));
        $this->assertNotSame('APPLIED', AdvanceDepositContract::state('POSTED', 'APPLY'));
    }

    public function test_application_scope_is_same_party_warehouse_and_expected_ledger(): void
    {
        AdvanceDepositContract::assertApplicationScope(9, 'CUSTOMER', 11, 'AR', 'CUSTOMER', 11, 9, 'DEBIT');
        AdvanceDepositContract::assertApplicationScope(9, 'SUPPLIER', 12, 'AP', 'SUPPLIER', 12, 9, 'CREDIT');

        $this->expectException(InvalidArgumentException::class);
        AdvanceDepositContract::assertApplicationScope(9, 'CUSTOMER', 11, 'AP', 'CUSTOMER', 11, 9, 'CREDIT');
    }

    public function test_application_scope_rejects_cross_warehouse_or_party(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AdvanceDepositContract::assertApplicationScope(9, 'CUSTOMER', 11, 'AR', 'CUSTOMER', 12, 9, 'DEBIT');
    }

    public function test_reversal_is_not_allowed_without_a_meaningful_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AdvanceDepositContract::state('VOID', 'REVERSE');
    }
}
