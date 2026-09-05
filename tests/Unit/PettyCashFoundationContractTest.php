<?php

namespace Tests\Unit;

use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\PettyCashVoucher;
use App\Modules\Finance\Models\PettyCashVoucherLine;
use App\Modules\Finance\Support\PettyCashVoucherContract;
use InvalidArgumentException;
use Tests\TestCase;

final class PettyCashFoundationContractTest extends TestCase
{
    public function test_voucher_state_machine_preserves_posted_history(): void
    {
        self::assertSame('SUBMITTED', PettyCashVoucherContract::state('DRAFT', 'SUBMIT'));
        self::assertSame('APPROVED', PettyCashVoucherContract::state('SUBMITTED', 'APPROVE'));
        self::assertSame('POSTED', PettyCashVoucherContract::state('APPROVED', 'POST'));
        self::assertSame('REVERSED', PettyCashVoucherContract::state('POSTED', 'REVERSE'));

        $this->expectException(InvalidArgumentException::class);
        PettyCashVoucherContract::state('POSTED', 'VOID');
    }

    public function test_only_active_cash_account_in_same_warehouse_can_fund_petty_cash(): void
    {
        PettyCashVoucherContract::assertCashFundBankAccount(new BankAccount(['warehouse_id' => 8, 'type' => 'CASH', 'is_active' => true]), 8);

        $this->expectException(InvalidArgumentException::class);
        PettyCashVoucherContract::assertCashFundBankAccount(new BankAccount(['warehouse_id' => 8, 'type' => 'BANK', 'is_active' => true]), 8);
    }

    public function test_posted_metadata_and_line_snapshots_are_contractual(): void
    {
        PettyCashVoucherContract::assertPostingMetadata(str_repeat('a', 64), 12);
        self::assertContains('journal_entry_id', (new PettyCashVoucher)->getFillable());
        self::assertContains('idempotency_key', (new PettyCashVoucher)->getFillable());
        self::assertContains('expense_category_name', (new PettyCashVoucherLine)->getFillable());
        self::assertContains('expense_account_name', (new PettyCashVoucherLine)->getFillable());

        $this->expectException(InvalidArgumentException::class);
        PettyCashVoucherContract::assertMutable('POSTED');
    }

    public function test_foundation_schema_is_a_dedicated_warehouse_scoped_subledger(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_04_150000_create_finance_petty_cash_foundation.php'));

        self::assertStringContainsString("Schema::create('finance_petty_cash_funds'", $migration);
        self::assertStringContainsString("Schema::create('finance_petty_cash_vouchers'", $migration);
        self::assertStringContainsString("Schema::create('finance_petty_cash_voucher_lines'", $migration);
        self::assertStringContainsString("['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'REVERSED', 'VOID']", $migration);
        self::assertStringContainsString("['warehouse_id', 'bank_account_id']", $migration);
        self::assertStringContainsString("->char('idempotency_key', 64)", $migration);
        self::assertStringContainsString("->foreignId('journal_entry_id')", $migration);
    }

    public function test_phase_two_service_reuses_atomic_journal_audit_and_sequence_boundaries(): void
    {
        $service = file_get_contents(base_path('app/Modules/Finance/Services/PettyCashVoucherService.php'));

        self::assertStringContainsString('JournalPostingService', $service);
        self::assertStringContainsString('DocumentSequenceService', $service);
        self::assertStringContainsString('AuditLogger', $service);
        self::assertStringContainsString('postWithinTransaction', $service);
        self::assertStringContainsString('reverseWithinTransaction', $service);
        self::assertStringContainsString("'event_code' => 'expense_payment'", $service);
        self::assertStringContainsString("if (\$voucher->status !== 'DRAFT')", $service);
        self::assertStringContainsString("hash('sha256', \"finance.petty_cash_voucher.post|{\$voucher->id}\")", $service);
    }

    public function test_voucher_service_converts_journal_balance_cents_to_baht_before_fund_limit_check(): void
    {
        $source = file_get_contents(base_path('app/Modules/Finance/Services/PettyCashVoucherService.php'));

        self::assertStringContainsString('JournalBalance::decimal(JournalBalance::totals', $source);
        self::assertStringContainsString("['debit'] / 100)", $source);
    }
}
