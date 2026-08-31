<?php

namespace Tests\Support;

use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\JournalBook;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Finance\Support\AdvanceDepositPostingContract;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Read-only foundation selector for the opt-in Finance MySQL integration.
 *
 * The test owns the outer transaction. This fixture never seeds or deletes
 * persistent ERP data; it only creates the temporary Settlement source inside
 * that transaction and lets the test roll it back.
 */
final class AdvanceDepositMySqlIntegrationFixture
{
    public static function assertReady(): void
    {
        foreach ([
            'finance_settlements' => ['journal_entry_id', 'bank_account_id', 'party_id', 'status'],
            'finance_bank_accounts' => ['warehouse_id', 'account_id', 'is_active'],
            'finance_advance_deposits' => ['source_settlement_id', 'journal_entry_id', 'idempotency_key'],
            'journal_entries' => ['id', 'warehouse_id', 'status'],
            'accounting_account_mappings' => ['key', 'account_id', 'is_active'],
        ] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Integration fixture requires table {$table}; run the approved migrations first.");
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    throw new RuntimeException("Integration fixture requires {$table}.{$column}; migration contract is incomplete.");
                }
            }
        }

    }

    /** @return array{warehouse: Warehouse, bank: BankAccount, party: Party, advanceAccountId: int} */
    public static function foundation(string $partyType): array
    {
        self::assertReady();
        $partyType = strtoupper($partyType);
        $role = $partyType === 'CUSTOMER' ? 'CUSTOMER' : ($partyType === 'SUPPLIER' ? 'SUPPLIER' : null);
        if ($role === null) {
            throw new RuntimeException('Fixture party type must be CUSTOMER or SUPPLIER.');
        }

        $mapping = $partyType === 'CUSTOMER' ? 'CUSTOMER_ADVANCE' : 'SUPPLIER_ADVANCE';
        if (AccountMapping::query()->where('key', $mapping)->where('is_active', true)->count() !== 1) {
            throw new RuntimeException("{$mapping} mapping must have exactly one active row.");
        }

        $journalEvent = $partyType === 'CUSTOMER' ? 'customer_advance' : 'supplier_payment';
        if (! JournalBook::query()->where('type', $partyType === 'CUSTOMER' ? 'RECEIPT' : 'PAYMENT')->where('is_active', true)->exists()) {
            throw new RuntimeException("{$journalEvent} journal book is not ready.");
        }

        $party = Party::query()->where('is_active', true)->whereHas('roles', fn ($query) => $query->where('role', $role)->where('is_active', true))->orderBy('id')->first();
        if (! $party) {
            throw new RuntimeException("No active {$role} party is available for the dedicated integration DB.");
        }

        $bank = BankAccount::query()->where('is_active', true)->whereNotNull('account_id')->with('warehouse')->orderBy('id')->first();
        if (! $bank || ! $bank->warehouse) {
            throw new RuntimeException('No active warehouse-scoped bank account is available for the dedicated integration DB.');
        }
        $warehouse = $bank->warehouse;
        $advanceAccountId = (int) AccountMapping::query()->where('key', $mapping)->where('is_active', true)->value('account_id');

        return compact('warehouse', 'bank', 'party', 'advanceAccountId');
    }

    public static function createPostedSettlement(User $actor, string $partyType): Settlement
    {
        $foundation = self::foundation($partyType);
        $suffix = strtoupper(Str::random(12));
        $date = now()->toDateString();
        $documentType = strtoupper($partyType) === 'CUSTOMER' ? 'RECEIPT' : 'PAYMENT';

        $settlement = Settlement::query()->create([
            'document_type' => $documentType,
            'document_number' => 'SETTLE-ADV-INT-'.$suffix,
            'document_date' => $date,
            'settlement_date' => $date,
            'party_type' => strtoupper($partyType),
            'party_id' => (string) $foundation['party']->id,
            'bank_account_id' => $foundation['bank']->id,
            'journal_entry_id' => null,
            'gross_amount' => '100.00',
            'tax_amount' => '0.00',
            'withholding_amount' => '0.00',
            'net_amount' => '100.00',
            'status' => 'POSTED',
            'description' => 'Dedicated rollback-only Advance/Deposit integration fixture',
            'created_by' => $actor->id,
        ]);
        $journal = app(JournalPostingService::class)->post([
            'source_type' => 'FINANCE', 'source_id' => (string) $settlement->id,
            'source_reference' => $settlement->document_number,
            'event_code' => $partyType === 'CUSTOMER' ? 'customer_advance' : 'supplier_payment',
            'entry_date' => $date, 'document_date' => $date,
            'description' => $settlement->description,
            'lines' => AdvanceDepositPostingContract::sourceLines(
                strtoupper($partyType), (int) $foundation['bank']->account_id, (int) $foundation['advanceAccountId'], '100.00', $settlement->document_number,
            ),
        ], $foundation['warehouse'], $actor);
        $settlement->update(['journal_entry_id' => $journal->id]);

        return $settlement->fresh();
    }

    public static function createOpenItem(User $actor, Settlement $settlement): OpenItem
    {
        $partyType = strtoupper($settlement->party_type);
        $foundation = self::foundation($partyType);
        $date = now()->toDateString();
        $number = 'INV-ADV-INT-'.strtoupper(Str::random(12));
        $mappings = app(AccountMappingService::class);
        $control = $mappings->resolve($partyType === 'CUSTOMER' ? 'SALES_AR' : 'PURCHASE_AP');
        $counterpart = $mappings->resolve($partyType === 'CUSTOMER' ? 'SALES_REVENUE_DEFAULT' : 'PURCHASE_EXPENSE_DEFAULT');
        $journal = app(JournalPostingService::class)->post([
            'source_type' => 'FINANCE', 'source_id' => 'OPEN-'.strtoupper(Str::random(12)), 'source_reference' => $number,
            'event_code' => $partyType === 'CUSTOMER' ? 'sales_invoice' : 'supplier_invoice.expense',
            'entry_date' => $date, 'document_date' => $date, 'description' => 'Dedicated rollback-only Open Item fixture',
            'lines' => $partyType === 'CUSTOMER' ? [
                ['account_id' => $control->id, 'subledger_type' => 'CUSTOMER', 'subledger_id' => (string) $foundation['party']->id, 'description' => $number, 'debit' => '100.00', 'credit' => '0.00'],
                ['account_id' => $counterpart->id, 'subledger_type' => null, 'subledger_id' => null, 'description' => $number, 'debit' => '0.00', 'credit' => '100.00'],
            ] : [
                ['account_id' => $counterpart->id, 'subledger_type' => null, 'subledger_id' => null, 'description' => $number, 'debit' => '100.00', 'credit' => '0.00'],
                ['account_id' => $control->id, 'subledger_type' => 'SUPPLIER', 'subledger_id' => (string) $foundation['party']->id, 'description' => $number, 'debit' => '0.00', 'credit' => '100.00'],
            ],
        ], $foundation['warehouse'], $actor);
        $line = $journal->lines()->where('account_id', $control->id)->firstOrFail();

        return app(OpenItemService::class)->recordFromJournalLine($line, [
            'document_type' => 'INVOICE', 'document_number' => $number, 'due_date' => now()->addDays(30)->toDateString(),
        ]);
    }
}
