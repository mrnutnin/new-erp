<?php

namespace Tests\Unit;

use Database\Seeders\InventoryGlMockupSeeder;
use Tests\TestCase;

class InventoryGlMockupSeederTest extends TestCase
{
    public function test_seeder_contract_has_stable_mock_keys_and_no_posted_fixture(): void
    {
        $source = file_get_contents(base_path('database/seeders/InventoryGlMockupSeeder.php'));

        $this->assertSame('MOCK-ITEM-001', InventoryGlMockupSeeder::ITEM_CODE);
        $this->assertSame('PI-INVENTORY-MOCK-001', InventoryGlMockupSeeder::PURCHASE_NUMBER);
        $this->assertStringContainsString("'status' => 'DRAFT'", $source);
        $this->assertStringNotContainsString("'status' => 'POSTED'", $source);
        $this->assertStringContainsString("'control_account_type' => 'INVENTORY'", $source);
        $this->assertStringContainsString("'is_stock_item' => true", $source);
    }

    public function test_fixture_is_transactional_and_has_no_queue_or_sequence_side_effects(): void
    {
        $source = file_get_contents(base_path('database/seeders/InventoryGlMockupSeeder.php'));

        $this->assertStringContainsString('DB::transaction', $source);
        $this->assertStringNotContainsString('dispatch(', $source);
        $this->assertStringNotContainsString('DocumentSequence::', $source);
        $this->assertStringNotContainsString('JournalEntry::', $source);
    }
}
