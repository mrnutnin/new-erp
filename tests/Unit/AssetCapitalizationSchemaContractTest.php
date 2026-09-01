<?php

namespace Tests\Unit;

use App\Modules\Asset\Models\AssetCapitalization;
use App\Modules\Asset\Models\AssetCapitalizationLine;
use App\Modules\Asset\Models\AssetValueEvent;
use PHPUnit\Framework\TestCase;

final class AssetCapitalizationSchemaContractTest extends TestCase
{
    public function test_source_lines_are_indexed_for_allocation_not_uniquely_reserved(): void
    {
        $migration = file_get_contents(dirname(__DIR__, 2).'/database/migrations/2026_08_31_214000_create_asset_capitalizations_and_value_events.php');

        self::assertStringContainsString("dropUnique('assets_source_type_source_line_id_unique')", $migration);
        self::assertStringContainsString('asset_capitalization_line_source_idx', $migration);
        self::assertStringNotContainsString("unique(['source_type', 'source_id', 'source_line_id'])", $migration);
    }

    public function test_value_events_are_append_only_and_models_expose_their_lines(): void
    {
        self::assertSame('asset_capitalizations', (new AssetCapitalization)->getTable());
        self::assertSame('asset_capitalization_lines', (new AssetCapitalizationLine)->getTable());
        self::assertNull(AssetValueEvent::UPDATED_AT);

        $model = file_get_contents((new \ReflectionClass(AssetValueEvent::class))->getFileName());
        self::assertStringContainsString('static::updating', $model);
        self::assertStringContainsString('static::deleting', $model);
    }
}
