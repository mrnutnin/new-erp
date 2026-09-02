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
use App\Modules\Asset\Models\AssetDisposal;
use App\Modules\Asset\Services\AssetDisposalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Rollback-only proof for disposal/write-off mapping fallback and reversal. */
final class AssetDisposalPostingMySqlIntegrationTest extends TestCase
{
    public function test_sale_and_write_off_use_event_mappings_and_original_journal_reversal(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = User::query()->orderBy('id')->first();
        $branch = Branch::query()->where('is_active', true)->orderBy('id')->first();
        $period = FiscalPeriod::query()->where('status', 'OPEN')->orderBy('start_date')->first();
        $assets = Account::query()->whereNull('control_account_type')->where('is_active', true)->where('is_postable', true)->whereHas('type', fn ($query) => $query->where('code', 'ASSET'))->orderBy('id')->take(4)->get();
        $loss = Account::query()->whereNull('control_account_type')->where('is_active', true)->where('is_postable', true)->whereHas('type', fn ($query) => $query->where('code', 'EXPENSE'))->orderBy('id')->first();
        $gain = Account::query()->whereNull('control_account_type')->where('is_active', true)->where('is_postable', true)->whereHas('type', fn ($query) => $query->where('code', 'REVENUE'))->orderBy('id')->first();

        if (! $actor || ! $branch || ! $period || $assets->count() < 4 || ! $loss || ! $gain || ! JournalBook::query()->where('type', 'GENERAL')->where('is_active', true)->exists()) {
            $this->markTestSkipped('ต้องมี User, Branch, งวด/สมุด GENERAL และบัญชี ASSET 4 บัญชี, EXPENSE, REVENUE ที่พร้อมใน local MySQL');
        }

        DB::beginTransaction();
        try {
            $tag = strtoupper(Str::random(12));
            $this->setMappings([
                'ASSET_COST' => $assets[0], 'ACCUMULATED_DEPRECIATION' => $assets[1], 'ACCUMULATED_IMPAIRMENT' => $assets[2],
                'DISPOSAL_CLEARING' => $assets[3], 'DISPOSAL_GAIN' => $gain, 'DISPOSAL_LOSS' => $loss,
            ], $actor);
            $category = AssetCategory::query()->create([
                'code' => 'QA-DSP-'.$tag, 'name' => 'หมวดทดสอบจำหน่าย Mapping', 'is_depreciable' => true,
                'capitalization_threshold' => '0.00', 'book_method' => 'STRAIGHT_LINE', 'book_residual_value_percent' => '0.0000',
                'tax_method' => 'STRAIGHT_LINE', 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);

            $saleAsset = $this->asset($category, $branch, $period, $actor, $tag.'-SALE');
            $this->finalDepreciationRun($branch, $period, $actor, $tag, [$saleAsset]);
            $sale = $this->disposal($saleAsset, $branch, $period, $actor, $tag.'-SALE', 'SALE', 1000);
            $postedSale = app(AssetDisposalService::class)->post($sale, $actor);
            $saleJournal = $postedSale->journalEntry()->with('lines')->firstOrFail();

            self::assertSame('POSTED', $postedSale->status);
            self::assertSame(['ASSET_COST', 'ACCUMULATED_DEPRECIATION', 'ACCUMULATED_IMPAIRMENT', 'DISPOSAL_CLEARING', 'DISPOSAL_GAIN'], collect($saleJournal->posting_metadata['accounts'])->pluck('account_role')->all());
            self::assertTrue(collect($saleJournal->posting_metadata['accounts'])->every(fn (array $account): bool => $account['source'] === 'MAPPING'));
            self::assertTrue(collect($saleJournal->posting_metadata['accounts'])->every(fn (array $account): bool => $saleJournal->lines->contains('account_id', $account['account_id'])));
            self::assertSame('1000.00', (string) $saleJournal->lines->where('account_id', $assets[3]->id)->sole()->debit);
            self::assertSame('100.00', (string) $saleJournal->lines->where('account_id', $gain->id)->sole()->credit);
            self::assertSame('DISPOSED', $saleAsset->fresh()->status);
            self::assertSame('0.00', (string) $saleAsset->fresh()->book_value);

            $retry = app(AssetDisposalService::class)->post($postedSale, $actor);
            self::assertSame($postedSale->journal_entry_id, $retry->journal_entry_id);

            $reversal = app(AssetDisposalService::class)->reverse($postedSale, $period->start_date->toDateString(), 'ทดสอบกลับรายการจำหน่ายจาก Journal ต้นฉบับ', $actor);
            $reversalJournal = $reversal->journalEntry()->with('lines')->firstOrFail();
            self::assertSame('100.00', (string) $reversalJournal->lines->where('account_id', $gain->id)->sole()->debit);
            self::assertSame('ACTIVE', $saleAsset->fresh()->status);
            self::assertSame('900.00', (string) $saleAsset->fresh()->book_value);

            $writeOff = $this->disposal($saleAsset, $branch, $period, $actor, $tag.'-WRITE', 'WRITE_OFF', 0);
            $postedWriteOff = app(AssetDisposalService::class)->post($writeOff, $actor);
            $writeOffJournal = $postedWriteOff->journalEntry()->with('lines')->firstOrFail();

            self::assertSame('POSTED', $postedWriteOff->status);
            self::assertSame('asset.write_off', $writeOffJournal->source_event);
            self::assertSame(['ASSET_COST', 'ACCUMULATED_DEPRECIATION', 'ACCUMULATED_IMPAIRMENT', 'DISPOSAL_LOSS'], collect($writeOffJournal->posting_metadata['accounts'])->pluck('account_role')->all());
            self::assertTrue(collect($writeOffJournal->posting_metadata['accounts'])->every(fn (array $account): bool => $writeOffJournal->lines->contains('account_id', $account['account_id'])));
            self::assertSame('900.00', (string) $writeOffJournal->lines->where('account_id', $loss->id)->sole()->debit);
            self::assertSame('WRITTEN_OFF', $saleAsset->fresh()->status);
        } finally {
            DB::rollBack();
        }
    }

    private function asset(AssetCategory $category, Branch $branch, FiscalPeriod $period, User $actor, string $tag): Asset
    {
        $asset = Asset::query()->create([
            'asset_number' => 'QA-DSP-'.$tag, 'branch_id' => $branch->id, 'asset_category_id' => $category->id,
            'name' => 'สินทรัพย์ทดสอบจำหน่าย Mapping', 'registration_date' => $period->start_date,
            'acquisition_date' => $period->start_date, 'original_cost' => '1200.00', 'book_cost' => '1200.00',
            'book_accumulated_depreciation' => '200.00', 'book_accumulated_impairment' => '100.00', 'book_value' => '900.00',
            'status' => 'ACTIVE', 'is_depreciation_suspended' => false, 'source_type' => 'INTEGRATION_TEST', 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        $book = AssetDepreciationBook::query()->create([
            'asset_id' => $asset->id, 'book_type' => 'BOOK', 'method' => 'STRAIGHT_LINE', 'depreciable_cost' => '1200.00',
            'residual_value' => '0.00', 'useful_life_months' => 12, 'start_date' => $period->start_date,
            'accumulated_depreciation' => '200.00', 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);

        return $asset;
    }

    /** @param array<int, Asset> $assets */
    private function finalDepreciationRun(Branch $branch, FiscalPeriod $period, User $actor, string $tag, array $assets): void
    {
        $run = AssetDepreciationRun::query()->create([
            'document_number' => 'DR-DSP-'.$tag, 'branch_id' => $branch->id, 'fiscal_period_id' => $period->id,
            'book_type' => 'BOOK', 'run_through_date' => $period->start_date, 'status' => 'POSTED', 'asset_count' => count($assets),
            'total_depreciation' => '0.00', 'total_catch_up_adjustment' => '0.00', 'calculation_hash' => hash('sha256', $tag),
            'progress_percent' => 100, 'posted_by' => $actor->id, 'posted_at' => now(), 'created_by' => $actor->id, 'updated_by' => $actor->id,
        ]);
        foreach ($assets as $index => $asset) {
            $book = AssetDepreciationBook::query()->where('asset_id', $asset->id)->sole();
            $run->lines()->create([
                'asset_id' => $asset->id, 'asset_depreciation_book_id' => $book->id, 'line_number' => $index + 1, 'asset_number' => $asset->asset_number,
                'category_code' => $asset->category->code, 'category_name' => $asset->category->name, 'opening_cost' => '1200.00',
                'opening_accumulated_depreciation' => '200.00', 'opening_accumulated_impairment' => '100.00', 'period_depreciation' => '0.00',
                'catch_up_adjustment' => '0.00', 'closing_cost' => '1200.00', 'closing_accumulated_depreciation' => '200.00',
                'closing_accumulated_impairment' => '100.00', 'closing_book_value' => '900.00', 'calculation_input_snapshot' => [],
            ]);
        }
    }

    private function disposal(Asset $asset, Branch $branch, FiscalPeriod $period, User $actor, string $tag, string $type, float $proceeds): AssetDisposal
    {
        $disposal = AssetDisposal::query()->create([
            'document_number' => 'DS-'.$tag, 'branch_id' => $branch->id, 'disposal_type' => $type,
            'disposal_date' => $period->start_date, 'status' => 'APPROVED', 'proceeds' => $proceeds,
            'reason' => 'ทดสอบลงบัญชีจำหน่ายสินทรัพย์ผ่าน Event Mapping', 'approved_by' => $actor->id,
            'approved_at' => now(), 'created_by' => $actor->id,
        ]);
        $disposal->lines()->create([
            'asset_id' => $asset->id, 'original_status' => 'ACTIVE', 'cost' => '1200.00', 'accumulated_depreciation' => '200.00',
            'accumulated_impairment' => '100.00', 'carrying_amount' => '900.00', 'proceeds' => $proceeds, 'gain_loss' => $proceeds - 900,
        ]);

        return $disposal;
    }

    /** @param array<string, Account> $accounts */
    private function setMappings(array $accounts, User $actor): void
    {
        foreach ($accounts as $role => $account) {
            foreach (['asset.disposal', 'asset.write_off'] as $event) {
                if ($event === 'asset.write_off' && in_array($role, ['DISPOSAL_CLEARING', 'DISPOSAL_GAIN'], true)) {
                    continue;
                }
                $mapping = AccountMapping::query()->where('event_code', $event)->where('key', $role)->lockForUpdate()->first();
                if ($mapping) {
                    $mapping->update(['account_id' => $account->id, 'is_active' => true, 'version' => max(1, (int) $mapping->version + 1), 'updated_by' => $actor->id]);
                } else {
                    AccountMapping::query()->create(['event_code' => $event, 'key' => $role, 'account_id' => $account->id, 'is_active' => true, 'version' => 1, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
                }
            }
        }
    }
}
