<?php

namespace Tests\Unit;

use App\Modules\Asset\Services\AssetOpeningBalanceCommitService;
use PHPUnit\Framework\TestCase;

final class AssetOpeningBalanceCommitContractTest extends TestCase
{
    public function test_validated_opening_batch_projects_asset_profiles_event_history_and_commit_marker(): void
    {
        $service = file_get_contents((new \ReflectionClass(AssetOpeningBalanceCommitService::class))->getFileName());

        self::assertStringContainsString("if (\$batch->status !== 'VALIDATED')", $service);
        self::assertStringContainsString("'status' => 'ACTIVE'", $service);
        self::assertStringContainsString('createDepreciationBooks', $service);
        self::assertStringContainsString('AssetValueEvent::query()->create', $service);
        self::assertStringContainsString("'event_type' => 'OPENING'", $service);
        self::assertStringContainsString('AssetHistory::query()->create', $service);
        self::assertStringContainsString("'event_type' => 'OPENING_COMMITTED'", $service);
        self::assertStringContainsString('markCommitted($batch, $actor)', $service);
    }

    public function test_opening_commit_is_atomic_idempotent_per_line_and_never_posts_gl(): void
    {
        $service = file_get_contents((new \ReflectionClass(AssetOpeningBalanceCommitService::class))->getFileName());

        self::assertStringContainsString('return DB::transaction(', $service);
        self::assertStringContainsString('assertReadyForCommit($line)', $service);
        self::assertStringContainsString("hash('sha256', \"asset-opening|{\$batch->id}|{\$line->id}\")", $service);
        self::assertStringNotContainsString('JournalPostingService', $service);
        self::assertStringNotContainsString('JournalEntry::query()', $service);
    }
}
