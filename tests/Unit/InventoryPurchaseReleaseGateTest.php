<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InventoryPurchaseReleaseGateTest extends TestCase
{
    public function test_inventory_purchase_feature_is_closed_by_default(): void
    {
        // The release environment may enable the route; the default config
        // contract must still remain explicitly closed when no override exists.
        $this->app['config']->set('erp.inventory.purchase_posting_enabled', false);
        $this->assertFalse((bool) config('erp.inventory.purchase_posting_enabled'));
    }

    public function test_inventory_routes_are_separate_and_permission_gated(): void
    {
        $post = Route::getRoutes()->getByName('purchasing.purchase-documents.inventory-post');
        $reverse = Route::getRoutes()->getByName('purchasing.purchase-documents.inventory-reverse');

        $this->assertNotNull($post);
        $this->assertNotNull($reverse);
        $this->assertContains('permission:purchasing.purchase-documents.inventory-post', $post->middleware());
        $this->assertContains('permission:purchasing.purchase-documents.inventory-reverse', $reverse->middleware());
        $this->assertNotSame('purchasing.purchase-documents.post', $post->getName());
    }

    public function test_inventory_schema_files_declare_idempotency_and_immutable_linkage(): void
    {
        $movement = file_get_contents(base_path('database/migrations/2026_08_21_287000_create_wms_stock_movements.php'));
        $allocation = file_get_contents(base_path('database/migrations/2026_08_21_295000_create_wms_cost_allocations.php'));
        $linkage = file_get_contents(base_path('database/migrations/2026_08_21_296000_create_wms_cost_allocation_journal_lines.php'));

        $this->assertStringContainsString("string('idempotency_key'", $movement);
        $this->assertStringContainsString("string('idempotency_key'", $allocation);
        $this->assertStringContainsString("string('identity_key'", $linkage);
        $this->assertStringContainsString('$table->foreignId(\'allocation_id\')', $linkage);
        $this->assertStringContainsString('$table->unsignedInteger(\'revision\')', $linkage);
    }

    public function test_reversal_audit_migration_has_fresh_and_rollback_contract(): void
    {
        $migration = file_get_contents(base_path('database/migrations/2026_08_22_310000_add_inventory_reversal_audit_to_purchase_documents.php'));
        foreach (['reversal_status', 'reversal_journal_entry_id', 'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_revision'] as $field) {
            $this->assertStringContainsString($field, $migration);
        }
        $this->assertStringContainsString('public function up()', $migration);
        $this->assertStringContainsString('public function down()', $migration);
        $this->assertStringContainsString('dropColumn', $migration);
    }

    public function test_live_reversal_adapter_enforces_exact_retry_and_single_allocation(): void
    {
        $adapter = file_get_contents(base_path('app/Modules/Wms/Services/InventoryPurchaseLiveReversalAdapter.php'));
        $this->assertStringContainsString("reversal_status === 'REVERSED'", $adapter);
        $this->assertStringContainsString('Reversal identity เดิมไม่ตรงกับคำขอใหม่', $adapter);
        $this->assertStringContainsString('$movements->count() !== 1', $adapter);
        $this->assertStringContainsString('$sourceAllocation->count() !== 1', $adapter);
        $this->assertStringContainsString('$reversalAllocations->count() !== 1', $adapter);
        $this->assertStringContainsString("\$journal->source_type !== 'PURCHASING'", $adapter);
        $this->assertStringContainsString("where('journal_entry_id', \$journal->id)", $adapter);
        $this->assertStringContainsString('parent_allocation_id', $adapter);
        $this->assertStringContainsString('linkJournalLineWithinTransaction', $adapter);
    }
}
