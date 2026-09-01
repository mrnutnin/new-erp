<?php

namespace Tests\Unit;

use App\Modules\Asset\Services\AssetCountService;
use PHPUnit\Framework\TestCase;

final class AssetCountContractTest extends TestCase
{
    public function test_extra_counted_assets_are_follow_up_only_and_never_change_the_register_or_gl(): void
    {
        $service = file_get_contents((new \ReflectionClass(AssetCountService::class))->getFileName());
        $migration = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_09_01_050000_allow_extra_asset_count_lines.php');

        self::assertStringContainsString("'result' => 'EXTRA'", $service);
        self::assertStringContainsString("'result' => 'FOUND'", $service);
        self::assertStringContainsString("'follow_up_required' => true", $service);
        self::assertStringNotContainsString('JournalPostingService', $service);
        self::assertStringNotContainsString('Asset::query()->update', $service);
        self::assertStringContainsString("'EXTRA'", $migration);
        self::assertStringContainsString("foreignId('asset_id')->nullable()", $migration);
    }
}
