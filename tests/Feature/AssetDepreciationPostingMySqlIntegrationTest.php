<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalBook;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetDepreciationBook;
use App\Modules\Asset\Models\AssetDepreciationRun;
use App\Modules\Asset\Services\AssetDepreciationRunService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Rollback-only proof for depreciation mapping fallback, metadata and reversal. */
final class AssetDepreciationPostingMySqlIntegrationTest extends TestCase
{
    public function test_book_depreciation_uses_event_mappings_and_reverses_the_original_journal(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = User::query()->orderBy('id')->first();
        $branch = Branch::query()->where('is_active', true)->orderBy('id')->first();
        $period = FiscalPeriod::query()->where('status', 'OPEN')->orderBy('start_date')->first();
        $expense = Account::query()->whereNull('control_account_type')->where('is_active', true)->where('is_postable', true)->whereHas('type', fn ($query) => $query->where('code', 'EXPENSE'))->orderBy('id')->first();
        $accumulated = Account::query()->whereNull('control_account_type')->where('is_active', true)->where('is_postable', true)->whereHas('type', fn ($query) => $query->where('code', 'ASSET'))->orderBy('id')->first();

        if (! $actor || ! $branch || ! $period || ! $expense || ! $accumulated || ! JournalBook::query()->where('type', 'GENERAL')->where('is_active', true)->exists()) {
            $this->markTestSkipped('ต้องมี User, Branch, งวด/สมุด GENERAL และบัญชี EXPENSE/ASSET ที่พร้อมใน local MySQL');
        }
        if (AccountMapping::query()->where('event_code', 'asset.depreciation')->whereIn('key', ['DEPRECIATION_EXPENSE', 'ACCUMULATED_DEPRECIATION'])->where('is_active', true)->count() > 2) {
            $this->markTestSkipped('asset.depreciation mapping มีรายการซ้ำ จึงไม่สามารถสร้าง fixture ปลอดภัยได้');
        }

        DB::beginTransaction();
        try {
            $tag = strtoupper(Str::random(12));
            $this->setMapping('DEPRECIATION_EXPENSE', $expense, $actor);
            $this->setMapping('ACCUMULATED_DEPRECIATION', $accumulated, $actor);

            $category = AssetCategory::query()->create([
                'code' => 'QA-DEP-'.$tag, 'name' => 'หมวดทดสอบค่าเสื่อม Mapping', 'is_depreciable' => true,
                'capitalization_threshold' => '0.00', 'book_method' => 'STRAIGHT_LINE', 'book_residual_value_percent' => '0.0000',
                'tax_method' => 'STRAIGHT_LINE', 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $asset = Asset::query()->create([
                'asset_number' => 'QA-DEP-'.$tag, 'branch_id' => $branch->id, 'asset_category_id' => $category->id,
                'name' => 'สินทรัพย์ทดสอบค่าเสื่อม Mapping', 'registration_date' => $period->start_date,
                'acquisition_date' => $period->start_date, 'original_cost' => '1200.00', 'book_cost' => '1200.00', 'book_value' => '1200.00',
                'status' => 'ACTIVE', 'is_depreciation_suspended' => false, 'source_type' => 'INTEGRATION_TEST', 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $book = AssetDepreciationBook::query()->create([
                'asset_id' => $asset->id, 'book_type' => 'BOOK', 'method' => 'STRAIGHT_LINE', 'depreciable_cost' => '1200.00',
                'residual_value' => '0.00', 'useful_life_months' => 12, 'start_date' => $period->start_date,
                'accumulated_depreciation' => '0.00', 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $run = AssetDepreciationRun::query()->create([
                'document_number' => 'DR-TEST-'.$tag, 'branch_id' => $branch->id, 'fiscal_period_id' => $period->id,
                'book_type' => 'BOOK', 'run_through_date' => $period->start_date, 'status' => 'APPROVED', 'asset_count' => 1,
                'total_depreciation' => '100.00', 'total_catch_up_adjustment' => '0.00', 'calculation_hash' => hash('sha256', $tag),
                'progress_percent' => 100, 'approved_by' => $actor->id, 'approved_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $run->lines()->create([
                'asset_id' => $asset->id, 'asset_depreciation_book_id' => $book->id, 'line_number' => 1, 'asset_number' => $asset->asset_number,
                'category_code' => $category->code, 'category_name' => $category->name, 'opening_cost' => '1200.00',
                'opening_accumulated_depreciation' => '0.00', 'opening_accumulated_impairment' => '0.00', 'period_depreciation' => '100.00',
                'catch_up_adjustment' => '0.00', 'closing_cost' => '1200.00', 'closing_accumulated_depreciation' => '100.00',
                'closing_accumulated_impairment' => '0.00', 'closing_book_value' => '1100.00',
                'calculation_input_snapshot' => ['asset_category_id' => $category->id, 'depreciation_expense_account_id' => null, 'accumulated_depreciation_account_id' => null, 'last_depreciation_date' => null],
            ]);

            $posted = app(AssetDepreciationRunService::class)->post($run, $actor);
            $journal = $posted->journalEntry()->with('lines')->firstOrFail();
            $accounts = collect($journal->posting_metadata['accounts'] ?? []);

            self::assertSame('POSTED', $posted->status);
            self::assertSame(['DEPRECIATION_EXPENSE', 'ACCUMULATED_DEPRECIATION'], $accounts->pluck('account_role')->all());
            self::assertTrue($accounts->every(fn (array $account): bool => $account['source'] === 'MAPPING'));
            self::assertSame('100.00', (string) $journal->lines->where('account_id', $expense->id)->sole()->debit);
            self::assertSame('100.00', (string) $journal->lines->where('account_id', $accumulated->id)->sole()->credit);

            $retry = app(AssetDepreciationRunService::class)->post($posted, $actor);
            self::assertSame($posted->journal_entry_id, $retry->journal_entry_id);

            $reversed = app(AssetDepreciationRunService::class)->reverse($posted, $period->start_date->toDateString(), 'ทดสอบกลับรายการค่าเสื่อมจาก Journal ต้นฉบับ', $actor);
            $reversal = $reversed->reversalJournalEntry()->with('lines')->firstOrFail();
            self::assertSame('REVERSED', $reversed->status);
            self::assertSame('100.00', (string) $reversal->lines->where('account_id', $expense->id)->sole()->credit);
            self::assertSame('100.00', (string) $reversal->lines->where('account_id', $accumulated->id)->sole()->debit);
        } finally {
            DB::rollBack();
        }
    }

    private function setMapping(string $role, Account $account, User $actor): void
    {
        $mapping = AccountMapping::query()->where('event_code', 'asset.depreciation')->where('key', $role)->lockForUpdate()->first();
        if ($mapping) {
            $mapping->update(['account_id' => $account->id, 'is_active' => true, 'version' => max(1, (int) $mapping->version + 1), 'updated_by' => $actor->id]);

            return;
        }

        AccountMapping::query()->create(['event_code' => 'asset.depreciation', 'key' => $role, 'account_id' => $account->id, 'is_active' => true, 'version' => 1, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
    }
}
