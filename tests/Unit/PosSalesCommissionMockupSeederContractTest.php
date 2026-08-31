<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosSalesCommissionMockupSeederContractTest extends TestCase
{
    public function test_mockup_seeder_has_one_active_and_two_safe_presets(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/database/seeders/PosSalesCommissionMockupSeeder.php');

        self::assertStringContainsString("'COMM-POSTED-3-MOCK'", $source);
        self::assertStringContainsString("'COMM-COLLECTED-2-MOCK'", $source);
        self::assertStringContainsString("'COMM-GP-5-MOCK'", $source);
        self::assertStringContainsString("'branch_id' => \$branch->id", $source);
    }
}
