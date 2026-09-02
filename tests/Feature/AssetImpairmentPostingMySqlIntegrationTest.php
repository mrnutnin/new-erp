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
use App\Modules\Asset\Models\AssetImpairment;
use App\Modules\Asset\Services\AssetImpairmentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Rollback-only proof for impairment mapping fallback, metadata and reversal. */
final class AssetImpairmentPostingMySqlIntegrationTest extends TestCase
{
    public function test_impairment_uses_event_mappings_and_reverses_the_original_journal(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = User::query()->orderBy('id')->first();
        $branch = Branch::query()->where('is_active', true)->orderBy('id')->first();
        $period = FiscalPeriod::query()->where('status', 'OPEN')->orderBy('start_date')->first();
        $loss = Account::query()->whereNull('control_account_type')->where('is_active', true)->where('is_postable', true)->whereHas('type', fn ($query) => $query->where('code', 'EXPENSE'))->orderBy('id')->first();
        $accumulated = Account::query()->whereNull('control_account_type')->where('is_active', true)->where('is_postable', true)->whereHas('type', fn ($query) => $query->where('code', 'ASSET'))->orderBy('id')->first();

        if (! $actor || ! $branch || ! $period || ! $loss || ! $accumulated || ! JournalBook::query()->where('type', 'GENERAL')->where('is_active', true)->exists()) {
            $this->markTestSkipped('ต้องมี User, Branch, งวด/สมุด GENERAL และบัญชี EXPENSE/ASSET ที่พร้อมใน local MySQL');
        }

        DB::beginTransaction();
        try {
            $tag = strtoupper(Str::random(12));
            $this->setMapping('IMPAIRMENT_LOSS', $loss, $actor);
            $this->setMapping('ACCUMULATED_IMPAIRMENT', $accumulated, $actor);

            $category = AssetCategory::query()->create([
                'code' => 'QA-IMP-'.$tag, 'name' => 'หมวดทดสอบด้อยค่า Mapping', 'is_depreciable' => true,
                'capitalization_threshold' => '0.00', 'book_method' => 'STRAIGHT_LINE', 'book_residual_value_percent' => '0.0000',
                'tax_method' => 'STRAIGHT_LINE', 'is_active' => true, 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $asset = Asset::query()->create([
                'asset_number' => 'QA-IMP-'.$tag, 'branch_id' => $branch->id, 'asset_category_id' => $category->id,
                'name' => 'สินทรัพย์ทดสอบด้อยค่า Mapping', 'registration_date' => $period->start_date,
                'acquisition_date' => $period->start_date, 'original_cost' => '1200.00', 'book_cost' => '1200.00', 'book_value' => '1200.00',
                'status' => 'ACTIVE', 'is_depreciation_suspended' => false, 'source_type' => 'INTEGRATION_TEST', 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $impairment = AssetImpairment::query()->create([
                'document_number' => 'IM-TEST-'.$tag, 'asset_id' => $asset->id, 'branch_id' => $branch->id,
                'assessment_date' => $period->start_date, 'status' => 'APPROVED', 'carrying_amount' => '1200.00',
                'recoverable_amount' => '900.00', 'impairment_amount' => '300.00', 'reason' => 'ทดสอบลงบัญชีด้อยค่าจาก Event Mapping',
                'approved_by' => $actor->id, 'approved_at' => now(), 'created_by' => $actor->id,
            ]);

            $posted = app(AssetImpairmentService::class)->post($impairment, $actor);
            $journal = $posted->journalEntry()->with('lines')->firstOrFail();
            $accounts = collect($journal->posting_metadata['accounts'] ?? []);

            self::assertSame('POSTED', $posted->status);
            self::assertSame(['IMPAIRMENT_LOSS', 'ACCUMULATED_IMPAIRMENT'], $accounts->pluck('account_role')->all());
            self::assertTrue($accounts->every(fn (array $account): bool => $account['source'] === 'MAPPING'));
            self::assertSame('300.00', (string) $journal->lines->where('account_id', $loss->id)->sole()->debit);
            self::assertSame('300.00', (string) $journal->lines->where('account_id', $accumulated->id)->sole()->credit);
            self::assertSame('900.00', (string) $asset->fresh()->book_value);
            self::assertSame('300.00', (string) $asset->fresh()->book_accumulated_impairment);

            $retry = app(AssetImpairmentService::class)->post($posted, $actor);
            self::assertSame($posted->journal_entry_id, $retry->journal_entry_id);

            $reversal = app(AssetImpairmentService::class)->reverse($posted, 'IM-REV-'.$tag, 'ทดสอบกลับรายการด้อยค่าจาก Journal ต้นฉบับ', $actor);
            $reversalJournal = $reversal->journalEntry()->with('lines')->firstOrFail();
            self::assertSame('POSTED', $reversal->status);
            self::assertSame('300.00', (string) $reversalJournal->lines->where('account_id', $loss->id)->sole()->credit);
            self::assertSame('300.00', (string) $reversalJournal->lines->where('account_id', $accumulated->id)->sole()->debit);
            self::assertSame('1200.00', (string) $asset->fresh()->book_value);
            self::assertSame('0.00', (string) $asset->fresh()->book_accumulated_impairment);
        } finally {
            DB::rollBack();
        }
    }

    private function setMapping(string $role, Account $account, User $actor): void
    {
        $mapping = AccountMapping::query()->where('event_code', 'asset.impairment')->where('key', $role)->lockForUpdate()->first();
        if ($mapping) {
            $mapping->update(['account_id' => $account->id, 'is_active' => true, 'version' => max(1, (int) $mapping->version + 1), 'updated_by' => $actor->id]);

            return;
        }

        AccountMapping::query()->create(['event_code' => 'asset.impairment', 'key' => $role, 'account_id' => $account->id, 'is_active' => true, 'version' => 1, 'created_by' => $actor->id, 'updated_by' => $actor->id]);
    }
}
