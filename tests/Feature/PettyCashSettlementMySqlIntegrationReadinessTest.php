<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Models\JournalBook;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\DocumentSequenceCounter;
use App\Modules\Finance\Models\PettyCashClearing;
use App\Modules\Finance\Models\PettyCashFund;
use App\Modules\Finance\Models\PettyCashTopUp;
use App\Modules\Finance\Services\PettyCashClearingService;
use App\Modules\Finance\Services\PettyCashTopUpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PettyCashSettlementMySqlIntegrationReadinessTest extends TestCase
{
    public function test_petty_cash_top_up_and_clearing_post_balanced_gl_and_reverse(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = User::query()->orderBy('id')->first();
        $warehouse = $actor?->warehouses()->where('warehouses.is_active', true)->with('branch')->orderBy('warehouses.id')->first();
        $fund = $warehouse ? PettyCashFund::query()->where('warehouse_id', $warehouse->id)->where('is_active', true)->with('cashBankAccount.account')->orderBy('id')->first() : null;
        $source = $warehouse ? BankAccount::query()->where('warehouse_id', $warehouse->id)->where('type', 'BANK')->where('is_active', true)->with('account')->whereHas('account', fn ($q) => $q->where('is_active', true)->where('is_postable', true))->orderBy('id')->first() : null;
        $topUpSequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PETTY_CASH_TOP_UP')->where('is_active', true)->first();
        $clearingSequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PETTY_CASH_CLEARING')->where('is_active', true)->first();

        if (! $actor || ! $warehouse || ! $fund || ! $fund->cashBankAccount || ! $source || ! $topUpSequence || ! $clearingSequence || ! JournalBook::query()->where('type', 'GENERAL')->where('is_active', true)->exists()) {
            $this->markTestSkipped('ต้องมี User, Warehouse, วงเงินสดย่อย, บัญชีธนาคาร, sequences และสมุด GENERAL');
        }

        DB::beginTransaction();
        try {
            $request = Request::create('/finance/petty-cash/top-ups', 'POST');
            $this->setBranchCounter($topUpSequence, $warehouse->branch_id, PettyCashTopUp::class, 'PCT-');
            $topUp = app(PettyCashTopUpService::class)->create([
                'petty_cash_fund_id' => $fund->id, 'source_bank_account_id' => $source->id,
                'document_date' => today()->toDateString(), 'amount' => '125.00', 'description' => 'Integration top up',
            ], $warehouse, $topUpSequence, $actor, $request);
            $topUpService = app(PettyCashTopUpService::class);
            $topUp = $topUpService->approve($topUpService->submit($topUp, $warehouse, $actor, $request), $warehouse, $actor, $request);
            $postedTopUp = $topUpService->post($topUp, $warehouse, $actor, $request)->load('journalEntry.lines');
            self::assertSame('POSTED', $postedTopUp->status);
            self::assertSame('125.00', number_format((float) $postedTopUp->journalEntry->lines->sum('debit'), 2, '.', ''));
            self::assertSame('125.00', number_format((float) $postedTopUp->journalEntry->lines->sum('credit'), 2, '.', ''));

            $clearingRequest = Request::create('/finance/petty-cash/clearings', 'POST');
            $this->setBranchCounter($clearingSequence, $warehouse->branch_id, PettyCashClearing::class, 'PCC-');
            $clearingService = app(PettyCashClearingService::class);
            $expected = (float) $fund->topUps()->where('status', 'POSTED')->sum('amount')
                - (float) $fund->vouchers()->where('status', 'POSTED')->sum('total_amount');
            $clearing = $clearingService->save(null, [
                'petty_cash_fund_id' => $fund->id, 'clearing_date' => today()->toDateString(),
                'actual_amount' => number_format($expected - 1, 2, '.', ''), 'reason' => 'Integration variance',
            ], $warehouse, $actor, $clearingRequest, $clearingSequence);
            $clearing = $clearingService->transition($clearing, $warehouse, 'submit', '', $actor, $clearingRequest);
            $clearing = $clearingService->transition($clearing, $warehouse, 'approve', '', $actor, $clearingRequest);
            $postedClearing = $clearingService->post($clearing, $warehouse, $actor, $clearingRequest)->load('journalEntry.lines');
            self::assertSame('POSTED', $postedClearing->status);
            self::assertSame('1.00', number_format((float) $postedClearing->journalEntry->lines->sum('debit'), 2, '.', ''));
            self::assertSame('1.00', number_format((float) $postedClearing->journalEntry->lines->sum('credit'), 2, '.', ''));

            $reversed = $clearingService->reverse($postedClearing, $warehouse, today()->toDateString(), 'Integration reversal test', $actor, $clearingRequest)->load('reversalJournalEntry.lines');
            self::assertSame('REVERSED', $reversed->status);
            self::assertSame('POSTED', $reversed->reversalJournalEntry->status);
            self::assertSame('1.00', number_format((float) $reversed->reversalJournalEntry->lines->sum('debit'), 2, '.', ''));
            self::assertSame('1.00', number_format((float) $reversed->reversalJournalEntry->lines->sum('credit'), 2, '.', ''));
        } finally {
            DB::rollBack();
        }
    }

    private function setBranchCounter(DocumentSequence $sequence, int $branchId, string $model, string $prefix): void
    {
        $lastNumber = $model::withTrashed()->where('document_number', 'like', $prefix.'%')->pluck('document_number')
            ->map(fn (string $number): int => (int) substr($number, strrpos($number, '-') + 1))->max() ?? 0;
        $counter = DocumentSequenceCounter::query()->firstOrCreate(
            ['document_sequence_id' => $sequence->id, 'branch_id' => $branchId],
            ['next_number' => 1],
        );
        $counter->update(['next_number' => $lastNumber + 1]);
    }
}
