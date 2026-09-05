<?php

namespace Tests\Unit;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Support\PostingEvent;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\PettyCashTopUp;
use App\Modules\Finance\Support\PettyCashTopUpContract;
use InvalidArgumentException;
use Tests\TestCase;

final class PettyCashTopUpContractTest extends TestCase
{
    public function test_top_up_state_machine_preserves_posted_history(): void
    {
        self::assertSame('SUBMITTED', PettyCashTopUpContract::state('DRAFT', 'SUBMIT'));
        self::assertSame('APPROVED', PettyCashTopUpContract::state('SUBMITTED', 'APPROVE'));
        self::assertSame('POSTED', PettyCashTopUpContract::state('APPROVED', 'POST'));
        self::assertSame('REVERSED', PettyCashTopUpContract::state('POSTED', 'REVERSE'));
        $this->expectException(InvalidArgumentException::class);
        PettyCashTopUpContract::state('POSTED', 'VOID');
    }

    public function test_only_active_postable_bank_in_same_warehouse_is_a_source(): void
    {
        $bank = (new BankAccount(['warehouse_id' => 8, 'type' => 'BANK', 'is_active' => true]))->setRelation('account', new Account(['is_active' => true, 'is_postable' => true]));
        PettyCashTopUpContract::assertSourceBankAccount($bank, 8);
        $this->expectException(InvalidArgumentException::class);
        PettyCashTopUpContract::assertSourceBankAccount((new BankAccount(['warehouse_id' => 8, 'type' => 'CASH', 'is_active' => true]))->setRelation('account', new Account(['is_active' => true, 'is_postable' => true])), 8);
    }

    public function test_posting_event_and_metadata_are_dedicated_to_top_up(): void
    {
        self::assertSame('PAYMENT', PostingEvent::bookType('petty_cash_top_up'));
        self::assertSame([], PostingEvent::roles('petty_cash_top_up'));
        PettyCashTopUpContract::assertPostingMetadata(str_repeat('a', 64), 12);
        self::assertContains('cash_account_name', (new PettyCashTopUp)->getFillable());
        self::assertContains('source_account_name', (new PettyCashTopUp)->getFillable());
    }

    public function test_schema_and_service_are_a_scoped_immutable_journal_boundary(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_09_04_160000_create_finance_petty_cash_top_ups.php'));
        $service = file_get_contents(base_path('app/Modules/Finance/Services/PettyCashTopUpService.php'));
        self::assertStringContainsString("Schema::create('finance_petty_cash_top_ups'", $migration);
        self::assertStringContainsString("['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'REVERSED', 'VOID']", $migration);
        self::assertStringContainsString("->char('idempotency_key', 64)", $migration);
        self::assertStringContainsString('DocumentSequenceService', $service);
        self::assertStringContainsString('JournalPostingService', $service);
        self::assertStringContainsString('AuditLogger', $service);
        self::assertStringContainsString("'event_code' => 'petty_cash_top_up'", $service);
        self::assertStringContainsString('postWithinTransaction', $service);
        self::assertStringContainsString('reverseWithinTransaction', $service);
    }
}
