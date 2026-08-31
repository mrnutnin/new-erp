<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesIntakePriceSnapshotContractTest extends TestCase
{
    public function test_intake_snapshots_price_list_terms_unless_a_promotion_overrides_them(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Controllers/SalesIntakeController.php');

        self::assertStringContainsString('$priceSnapshot = app(PriceListResolver::class)->resolve', $source);
        self::assertStringContainsString("'pricing_snapshot' => \$promotion ?? \$priceSnapshot", $source);
        self::assertStringContainsString("\$standard = (string) (\$promotion['base_unit_price'] ?? \$promotion['unit_price']);", $source);
    }
}
