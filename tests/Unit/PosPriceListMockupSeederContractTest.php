<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosPriceListMockupSeederContractTest extends TestCase
{
    public function test_mockup_seeder_has_retail_wholesale_and_quantity_tiers(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/database/seeders/PosPriceListMockupSeeder.php');

        self::assertStringContainsString("'RETAIL-MOCK'", $source);
        self::assertStringContainsString("'WHOLESALE-MOCK'", $source);
        self::assertStringContainsString('[1, 10, 50]', $source);
        self::assertStringContainsString("'branch_id' => \$branchId", $source);
    }
}
