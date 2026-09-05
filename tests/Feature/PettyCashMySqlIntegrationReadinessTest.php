<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalBook;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\DocumentSequenceCounter;
use App\Modules\Finance\Models\OtherCategory;
use App\Modules\Finance\Models\PettyCashFund;
use App\Modules\Finance\Models\PettyCashVoucher;
use App\Modules\Finance\Services\PettyCashVoucherService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PettyCashMySqlIntegrationReadinessTest extends TestCase
{
    public function test_petty_cash_voucher_posts_balanced_gl_and_reverses_original_journal(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = User::query()->orderBy('id')->first();
        $warehouse = $actor?->warehouses()->where('warehouses.is_active', true)->with('branch')->orderBy('warehouses.id')->first();
        $fund = $warehouse
            ? PettyCashFund::query()->where('warehouse_id', $warehouse->id)->where('is_active', true)->with('cashBankAccount.account')->orderBy('id')->first()
            : null;
        $category = OtherCategory::query()->where('kind', 'EXPENSE')->where('is_active', true)->with('account')->orderBy('id')->first();
        $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'PETTY_CASH')->where('is_active', true)->first()
            ?? ($warehouse ? DocumentSequence::query()->where('warehouse_id', $warehouse->id)->where('document_type', 'PETTY_CASH')->where('is_active', true)->first() : null);

        if (! $actor || ! $warehouse || ! $fund || ! $fund->cashBankAccount || ! $category || ! $category->account || ! $sequence || ! JournalBook::query()->where('type', 'GENERAL')->where('is_active', true)->exists()) {
            $this->markTestSkipped('ต้องมี User, Warehouse, วงเงินสดย่อย, หมวดค่าใช้จ่าย, sequence PETTY_CASH และสมุด GENERAL');
        }

        DB::beginTransaction();
        try {
            $lastNumber = PettyCashVoucher::withTrashed()->where('document_number', 'like', 'PC-%')->pluck('document_number')
                ->map(fn (string $number): int => (int) substr($number, strrpos($number, '-') + 1))->max() ?? 0;
            $counter = DocumentSequenceCounter::query()->firstOrCreate(
                ['document_sequence_id' => $sequence->id, 'branch_id' => $warehouse->branch_id],
                ['next_number' => 1],
            );
            $counter->update(['next_number' => $lastNumber + 1]);
            $request = Request::create('/finance/petty-cash', 'POST');
            $service = app(PettyCashVoucherService::class);
            $voucher = $service->create([
                'petty_cash_fund_id' => $fund->id,
                'document_date' => today()->toDateString(),
                'payee_type' => 'OTHER',
                'payee_name' => 'Integration test payee',
                'description' => 'Integration petty cash voucher',
                'lines' => [[
                    'expense_category_id' => $category->id,
                    'description' => 'Integration expense',
                    'receipt_reference' => 'INT-PC-001',
                    'amount' => '125.00',
                    'tax_code_id' => null,
                    'withholding_tax_code_id' => null,
                ]],
            ], $warehouse, $sequence, $actor, $request);
            $voucher = $service->submit($voucher, $warehouse, $actor, $request);
            $voucher = $service->approve($voucher, $warehouse, $actor, $request);
            $posted = $service->post($voucher, $warehouse, $actor, $request)->load('journalEntry.lines');
            $journal = $posted->journalEntry;

            self::assertSame('POSTED', $posted->status);
            self::assertSame('125.00', number_format((float) $journal->lines->sum('debit'), 2, '.', ''));
            self::assertSame('125.00', number_format((float) $journal->lines->sum('credit'), 2, '.', ''));
            self::assertSame([$category->account_id, $fund->cashBankAccount->account_id], $journal->lines->pluck('account_id')->all());

            $reversed = $service->reverse($posted, $warehouse, today()->toDateString(), 'Integration reversal test', $actor, $request)->load('reversalJournalEntry.lines');
            self::assertSame('REVERSED', $reversed->status);
            self::assertSame('POSTED', $reversed->reversalJournalEntry->status);
            self::assertSame('125.00', number_format((float) $reversed->reversalJournalEntry->lines->sum('debit'), 2, '.', ''));
            self::assertSame('125.00', number_format((float) $reversed->reversalJournalEntry->lines->sum('credit'), 2, '.', ''));
        } finally {
            DB::rollBack();
        }
    }
}
