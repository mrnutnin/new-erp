<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Services\AdvanceDepositApplicationService;
use App\Modules\Finance\Services\AdvanceDepositSettlementService;
use App\Modules\Pos\Services\AdvanceDepositPostingService;
use App\Modules\Pos\Services\AdvanceDepositRefundService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Support\AdvanceDepositMySqlIntegrationFixture;
use Tests\TestCase;

/**
 * Opt-in local-MySQL contract. The normal suite uses SQLite and skips this.
 * Every write is inside the explicit outer transaction and is rolled back.
 */
final class AdvanceDepositMySqlIntegrationReadinessTest extends TestCase
{
    public function test_customer_and_supplier_advance_source_flow_rolls_back_and_is_idempotent(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $this->assertSame('mysql', config('database.default'));
        AdvanceDepositMySqlIntegrationFixture::assertReady();
        $actor = User::query()->first();
        if (! $actor) {
            $this->markTestSkipped('ต้องมี User fixture ใน dedicated MySQL DB');
        }

        $before = $this->counts();
        DB::beginTransaction();
        try {
            $service = app(AdvanceDepositSettlementService::class);
            foreach (['CUSTOMER', 'SUPPLIER'] as $partyType) {
                $settlement = AdvanceDepositMySqlIntegrationFixture::createPostedSettlement($actor, $partyType);
                $advance = $service->postFromPostedSettlement($settlement, $settlement->bankAccount->warehouse, 'ADVANCE', $actor);
                $openItem = AdvanceDepositMySqlIntegrationFixture::createOpenItem($actor, $settlement);

                $this->assertSame('POSTED', $advance->status);
                $this->assertSame('100.00', (string) $advance->original_amount);
                $this->assertNotNull($advance->journal_entry_id);
                $this->assertSame(2, DB::table('journal_entry_lines')->where('journal_entry_id', $advance->journal_entry_id)->count());
                $this->assertSame(0.0, (float) DB::table('journal_entry_lines')->where('journal_entry_id', $advance->journal_entry_id)->sum(DB::raw('debit - credit')));

                $retry = $service->postFromPostedSettlement($settlement->fresh(), $settlement->bankAccount->warehouse, 'ADVANCE', $actor);
                $this->assertSame($advance->id, $retry->id, 'retry must return the existing advance row');
                $this->assertSame(1, DB::table('finance_advance_deposits')->where('source_settlement_id', $settlement->id)->count());

                $applicationService = app(AdvanceDepositApplicationService::class);
                $application = $applicationService->apply($advance, $openItem, [
                    'application_date' => now()->toDateString(), 'amount' => '40.00', 'source_type' => 'INTEGRATION', 'source_id' => 'ADV-APP-'.$settlement->id,
                ], $actor);
                $this->assertNotNull($application->journal_entry_id);
                $this->assertSame(2, DB::table('journal_entry_lines')->where('journal_entry_id', $application->journal_entry_id)->count());
                $this->assertSame(0.0, (float) DB::table('journal_entry_lines')->where('journal_entry_id', $application->journal_entry_id)->sum(DB::raw('debit - credit')));
                $retryApplication = $applicationService->apply($advance->fresh(), $openItem->fresh(), [
                    'application_date' => now()->toDateString(), 'amount' => '40.00', 'source_type' => 'INTEGRATION', 'source_id' => 'ADV-APP-'.$settlement->id,
                ], $actor);
                $this->assertSame($application->id, $retryApplication->id);
                $reversal = $applicationService->reverse($application, now()->toDateString(), 'Integration rollback test', $actor);
                $this->assertNotNull($reversal->reversal_journal_entry_id);
                $this->assertSame('REVERSED', DB::table('journal_entries')->where('id', $reversal->journal_entry_id)->value('status'));
                $this->assertSame('POSTED', DB::table('journal_entries')->where('id', $reversal->reversal_journal_entry_id)->value('status'));
            }
        } finally {
            DB::rollBack();
        }

        $this->assertSame($before, $this->counts(), 'rollback-only fixture must leave persistent counts unchanged');
    }

    public function test_unused_multi_tender_ai_refunds_each_original_cash_account(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        AdvanceDepositMySqlIntegrationFixture::assertReady();
        $actor = User::query()->first();
        if (! $actor) {
            $this->markTestSkipped('ต้องมี User fixture ใน dedicated MySQL DB');
        }

        DB::beginTransaction();
        try {
            $foundation = AdvanceDepositMySqlIntegrationFixture::foundation('CUSTOMER');
            $banks = BankAccount::query()->where('warehouse_id', $foundation['warehouse']->id)->where('is_active', true)->where('currency_code', 'THB')->with('account')->orderBy('id')->take(2)->get();
            if ($banks->count() !== 2) {
                $this->markTestSkipped('ต้องมีบัญชีรับเงิน THB อย่างน้อยสองบัญชีในคลัง fixture');
            }
            $draft = app(AdvanceDepositPostingService::class)->createDraft([
                'party_id' => $foundation['party']->id, 'document_date' => today()->toDateString(), 'receipt_date' => today()->toDateString(),
                'tax_treatment' => 'NONE_VAT', 'prices_include_vat' => false, 'original_amount' => '100.00',
                'tenders' => [['bank_account_id' => $banks[0]->id, 'amount' => '40.00'], ['bank_account_id' => $banks[1]->id, 'amount' => '60.00']],
            ], $foundation['warehouse'], $actor);
            $posted = app(AdvanceDepositPostingService::class)->post($draft, today()->toDateString(), $foundation['warehouse'], $actor, Request::create('/', 'POST'));
            $voided = app(AdvanceDepositRefundService::class)->refund($posted, today()->toDateString(), 'MySQL multi tender refund proof', $foundation['warehouse'], $actor, Request::create('/', 'POST'));
            $reversal = $voided->reversalJournalEntry()->with('lines')->firstOrFail();
            $cashCredits = $reversal->lines->whereIn('subledger_id', $banks->pluck('id')->map(fn (int $id): string => (string) $id))->pluck('credit')->sort()->values()->all();

            self::assertSame('VOID', $voided->status);
            self::assertNull($voided->refund_bank_account_id);
            self::assertSame(['40.00', '60.00'], $cashCredits);
            self::assertSame('100.00', JournalBalance::decimal($reversal->lines->sum('debit')));
            self::assertSame('100.00', JournalBalance::decimal($reversal->lines->sum('credit')));
            self::assertSame(['BANK_ACCOUNT_'.$banks[0]->id, 'BANK_ACCOUNT_'.$banks[1]->id], collect(data_get($posted->journalEntry?->posting_metadata, 'accounts', []))->pluck('account_role')->filter(fn (string $role): bool => str_starts_with($role, 'BANK_ACCOUNT_'))->values()->all());
        } finally {
            DB::rollBack();
        }
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return collect(['finance_settlements', 'finance_advance_deposits', 'journal_entries', 'journal_entry_lines'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])
            ->all();
    }
}
