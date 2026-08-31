<?php

namespace Tests\Unit;

use Tests\Support\InventoryPurchaseIntegrationFixture;
use Tests\TestCase;

final class InventoryPurchaseIntegrationFixtureTest extends TestCase
{
    public function test_fixture_declares_fk_order_without_hard_coded_ids_or_fake_journal(): void
    {
        $steps = InventoryPurchaseIntegrationFixture::steps();

        $this->assertSame('user', $steps[0]);
        $this->assertSame('approved_inventory_purchase', $steps[array_key_last($steps)]);
        $this->assertContains('goods_receipt', $steps);
        $this->assertContains('open_fiscal_period', $steps);
        $this->assertNotContains('fake_journal', $steps);
    }

    public function test_fixture_schema_contract_includes_inventory_linkage_and_idempotency(): void
    {
        $schema = InventoryPurchaseIntegrationFixture::requiredSchema();

        $this->assertContains('item_id', $schema['purchase_document_lines']);
        $this->assertContains('uom_id', $schema['purchase_document_lines']);
        $this->assertContains('idempotency_key', $schema['wms_stock_movements']);
        $this->assertContains('identity_key', $schema['wms_cost_allocation_journal_lines']);
    }
}
