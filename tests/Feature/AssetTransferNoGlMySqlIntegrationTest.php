<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Models\AssetHistory;
use App\Modules\Asset\Models\AssetValueEvent;
use App\Modules\Asset\Services\AssetTransferService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Rollback-only proof that a cross-branch transfer is an operational NO_GL event. */
final class AssetTransferNoGlMySqlIntegrationTest extends TestCase
{
    public function test_posted_cross_branch_transfer_moves_register_without_journal_or_value_event(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = User::query()->orderBy('id')->first();
        $branches = Branch::query()->where('is_active', true)->orderBy('id')->take(2)->get();
        $category = AssetCategory::query()->where('is_active', true)->orderBy('id')->first();
        if (! $actor || $branches->count() < 2 || ! $category) {
            $this->markTestSkipped('ต้องมี User, สาขาใช้งานอย่างน้อย 2 สาขา และหมวดสินทรัพย์ใน local MySQL');
        }

        DB::beginTransaction();
        try {
            $tag = strtoupper(Str::random(12));
            $asset = Asset::query()->create([
                'asset_number' => 'QA-TRF-'.$tag, 'branch_id' => $branches[0]->id, 'asset_category_id' => $category->id,
                'name' => 'สินทรัพย์ทดสอบโอนสาขา NO_GL', 'registration_date' => today(), 'acquisition_date' => today(),
                'original_cost' => '1000.00', 'book_cost' => '1000.00', 'book_value' => '1000.00', 'status' => 'ACTIVE',
                'is_depreciation_suspended' => false, 'source_type' => 'INTEGRATION_TEST', 'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
            $journalsBefore = JournalEntry::query()->count();
            $eventsBefore = AssetValueEvent::query()->count();
            $service = app(AssetTransferService::class);

            $draft = $service->createDraft($branches[0], [
                'document_number' => 'TRF-'.$tag, 'destination_branch_id' => $branches[1]->id,
                'document_date' => today()->toDateString(), 'reason' => 'ทดสอบโอนสาขาสินทรัพย์โดยไม่สร้างรายการบัญชี', 'asset_ids' => [$asset->id],
            ], $actor);
            $submitted = $service->submit($draft, $actor);
            $approved = $service->approve($submitted, $actor);
            $posted = $service->post($approved, $actor);

            self::assertSame('POSTED', $posted->status);
            self::assertSame($branches[1]->id, $asset->fresh()->branch_id);
            self::assertSame($journalsBefore, JournalEntry::query()->count());
            self::assertSame($eventsBefore, AssetValueEvent::query()->count());
            self::assertTrue(AssetHistory::query()->where('asset_id', $asset->id)->where('event_type', 'TRANSFER_POSTED')->where('source_id', $posted->id)->exists());
        } finally {
            DB::rollBack();
        }
    }
}
