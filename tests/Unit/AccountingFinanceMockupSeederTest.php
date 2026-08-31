<?php

namespace Tests\Unit;

use Database\Seeders\AccountingFinanceMockupSeeder;
use Tests\TestCase;

class AccountingFinanceMockupSeederTest extends TestCase
{
    public function test_seeder_has_explicit_advance_mappings_without_fake_advance_rows(): void
    {
        $source = file_get_contents(base_path('database/seeders/AccountingFinanceMockupSeeder.php'));

        $this->assertStringContainsString("'CUSTOMER_ADVANCE' => '21500'", $source);
        $this->assertStringContainsString("'SUPPLIER_ADVANCE' => '12500'", $source);
        $this->assertStringNotContainsString('AdvanceDeposit::query()', $source);
        $this->assertTrue(class_exists(AccountingFinanceMockupSeeder::class));
    }

    public function test_seeder_defines_inventory_gl_prerequisites_without_posted_journal_fixture(): void
    {
        $source = file_get_contents(base_path('database/seeders/AccountingFinanceMockupSeeder.php'));

        foreach (['INVENTORY_DEFAULT', 'COGS_DEFAULT', 'INVENTORY_ADJUSTMENT_GAIN', 'INVENTORY_ADJUSTMENT_LOSS', 'INVENTORY_RECOST_GAIN', 'INVENTORY_RECOST_LOSS'] as $key) {
            $this->assertStringContainsString("'{$key}' =>", $source);
        }
        $this->assertStringNotContainsString('JournalEntry::query()->create', $source);
    }
}
