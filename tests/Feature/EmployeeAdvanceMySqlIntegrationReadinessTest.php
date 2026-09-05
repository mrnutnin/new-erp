<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Models\JournalBook;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\DocumentSequenceCounter;
use App\Modules\Finance\Models\EmployeeAdvance;
use App\Modules\Finance\Models\EmployeeAdvanceClearing;
use App\Modules\Finance\Models\OtherCategory;
use App\Modules\Finance\Services\EmployeeAdvanceClearingService;
use App\Modules\Finance\Services\EmployeeAdvanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class EmployeeAdvanceMySqlIntegrationReadinessTest extends TestCase
{
    public function test_employee_advance_posts_balanced_gl_and_reverses_original_journal(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = User::query()->orderBy('id')->first();
        $employee = User::query()->where('id', '!=', $actor?->id)->where('is_active', true)->orderBy('id')->first();
        $warehouse = $actor?->warehouses()->where('warehouses.is_active', true)->with('branch')->orderBy('warehouses.id')->first();
        $bank = $warehouse
            ? BankAccount::query()->where('warehouse_id', $warehouse->id)->whereIn('type', ['BANK', 'CASH'])->where('is_active', true)->with('account')->whereHas('account', fn ($q) => $q->where('is_active', true)->where('is_postable', true))->orderBy('id')->first()
            : null;
        $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'EMPLOYEE_ADVANCE')->where('is_active', true)->first()
            ?? ($warehouse ? DocumentSequence::query()->where('warehouse_id', $warehouse->id)->where('document_type', 'EMPLOYEE_ADVANCE')->where('is_active', true)->first() : null);

        if (! $actor || ! $employee || ! $warehouse || ! $bank || ! $sequence || ! JournalBook::query()->where('type', 'GENERAL')->where('is_active', true)->exists()) {
            $this->markTestSkipped('ต้องมี User 2 คน, Warehouse, บัญชีจ่ายที่ลงรายการได้, sequence EMPLOYEE_ADVANCE และสมุด GENERAL');
        }

        DB::beginTransaction();
        try {
            $lastNumber = EmployeeAdvance::withTrashed()->where('document_number', 'like', 'EA-%')->pluck('document_number')
                ->map(fn (string $number): int => (int) substr($number, strrpos($number, '-') + 1))->max() ?? 0;
            $counter = DocumentSequenceCounter::query()->firstOrCreate(
                ['document_sequence_id' => $sequence->id, 'branch_id' => $warehouse->branch_id],
                ['next_number' => 1],
            );
            $counter->update(['next_number' => $lastNumber + 1]);
            $request = Request::create('/finance/employee-advances', 'POST');
            $service = app(EmployeeAdvanceService::class);
            $advance = $service->create([
                'employee_user_id' => $employee->id,
                'bank_account_id' => $bank->id,
                'document_date' => today()->toDateString(),
                'due_date' => today()->addDays(7)->toDateString(),
                'amount' => '125.00',
                'purpose' => 'Integration employee advance',
            ], $warehouse, $sequence, $actor, $request);
            $advance = $service->submit($advance, $warehouse, $actor, $request);
            $advance = $service->approve($advance, $warehouse, $actor, $request);
            $posted = $service->post($advance, $warehouse, $actor, $request)->load('journalEntry.lines');
            $journal = $posted->journalEntry;

            self::assertSame('POSTED', $posted->status);
            self::assertSame('125.00', number_format((float) $journal->lines->sum('debit'), 2, '.', ''));
            self::assertSame('125.00', number_format((float) $journal->lines->sum('credit'), 2, '.', ''));
            self::assertSame([$bank->account_id, $this->mappedAdvanceAccountId()], $journal->lines->pluck('account_id')->reverse()->values()->all());

            $reversed = $service->reverse($posted, $warehouse, today()->toDateString(), 'Integration reversal test', $actor, $request)->load('reversalJournalEntry.lines');
            self::assertSame('REVERSED', $reversed->status);
            self::assertSame('POSTED', $reversed->reversalJournalEntry->status);
            self::assertSame('125.00', number_format((float) $reversed->reversalJournalEntry->lines->sum('debit'), 2, '.', ''));
            self::assertSame('125.00', number_format((float) $reversed->reversalJournalEntry->lines->sum('credit'), 2, '.', ''));
        } finally {
            DB::rollBack();
        }
    }

    public function test_employee_advance_supports_partial_and_final_clearings_without_duplicate_release(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = User::query()->orderBy('id')->first();
        $employee = User::query()->where('id', '!=', $actor?->id)->where('is_active', true)->orderBy('id')->first();
        $warehouse = $actor?->warehouses()->where('warehouses.is_active', true)->with('branch')->orderBy('warehouses.id')->first();
        $bank = $warehouse
            ? BankAccount::query()->where('warehouse_id', $warehouse->id)->whereIn('type', ['BANK', 'CASH'])->where('is_active', true)->with('account')->whereHas('account', fn ($q) => $q->where('is_active', true)->where('is_postable', true))->orderBy('id')->first()
            : null;
        $category = OtherCategory::query()->where('kind', 'EXPENSE')->where('is_active', true)->with('account')->orderBy('id')->first();
        $advanceSequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'EMPLOYEE_ADVANCE')->where('is_active', true)->first();
        $clearingSequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'EMPLOYEE_ADVANCE_CLEARING')->where('is_active', true)->first();

        if (! $actor || ! $employee || ! $warehouse || ! $bank || ! $category?->account || ! $advanceSequence || ! $clearingSequence || ! JournalBook::query()->where('type', 'GENERAL')->where('is_active', true)->exists()) {
            $this->markTestSkipped('ต้องมี User 2 คน, Warehouse, บัญชีจ่าย, หมวดค่าใช้จ่าย, sequence และสมุด GENERAL');
        }

        DB::beginTransaction();
        try {
            $request = Request::create('/finance/employee-advance-clearings', 'POST');
            $advanceService = app(EmployeeAdvanceService::class);
            $clearingService = app(EmployeeAdvanceClearingService::class);
            $advance = $advanceService->create([
                'employee_user_id' => $employee->id,
                'bank_account_id' => $bank->id,
                'document_date' => today()->toDateString(),
                'due_date' => today()->addDays(7)->toDateString(),
                'amount' => '100.00',
                'purpose' => 'Integration partial clearing',
            ], $warehouse, $advanceSequence, $actor, $request);
            $advance = $advanceService->submit($advance, $warehouse, $actor, $request);
            $advance = $advanceService->approve($advance, $warehouse, $actor, $request);
            $advance = $advanceService->post($advance, $warehouse, $actor, $request);

            $line = fn (string $amount): array => [[
                'expense_category_id' => $category->id,
                'description' => 'Integration clearing',
                'receipt_reference' => 'INT-EAC-001',
                'amount' => $amount,
                'tax_code_id' => null,
                'withholding_tax_code_id' => null,
            ]];
            $save = fn (array $values): EmployeeAdvanceClearing => $clearingService->save(null, $values, $warehouse, $clearingSequence, $actor, $request);
            $approveAndPost = function (EmployeeAdvanceClearing $clearing) use ($clearingService, $warehouse, $actor, $request): EmployeeAdvanceClearing {
                $clearing = $clearingService->transition($clearing, $warehouse, 'submit', $actor, $request);
                $clearing = $clearingService->transition($clearing, $warehouse, 'approve', $actor, $request);
                return $clearingService->post($clearing, $warehouse, $actor, $request)->load('journalEntry.lines');
            };

            $partial = $approveAndPost($save([
                'employee_advance_id' => $advance->id,
                'document_date' => today()->toDateString(),
                'description' => 'Partial 1',
                'is_final' => false,
                'lines' => $line('40.00'),
            ]));
            self::assertSame('POSTED', $partial->status);
            self::assertSame('PARTIAL', $partial->advance->status);
            self::assertSame('40.00', number_format((float) $partial->journalEntry->lines->sum('debit'), 2, '.', ''));
            self::assertSame('40.00', number_format((float) $partial->journalEntry->lines->sum('credit'), 2, '.', ''));

            $final = $approveAndPost($save([
                'employee_advance_id' => $advance->id,
                'document_date' => today()->toDateString(),
                'description' => 'Final 2',
                'is_final' => true,
                'lines' => $line('60.00'),
            ]));
            self::assertSame('CLEARED', $final->status);
            self::assertSame('CLEARED', $final->advance->status);
            self::assertSame('60.00', number_format((float) $final->journalEntry->lines->sum('debit'), 2, '.', ''));
            self::assertSame('60.00', number_format((float) $final->journalEntry->lines->sum('credit'), 2, '.', ''));

            $reversed = $clearingService->reverse($partial, $warehouse, today()->toDateString(), 'Integration partial reversal', $actor, $request);
            self::assertSame('REVERSED', $reversed->status);
            self::assertSame('PARTIAL', $reversed->advance->status);
        } finally {
            DB::rollBack();
        }
    }

    private function mappedAdvanceAccountId(): int
    {
        return (int) DB::table('accounting_account_mappings')->where('event_code', 'employee_advance')->where('key', 'EMPLOYEE_ADVANCE')->value('account_id');
    }
}
