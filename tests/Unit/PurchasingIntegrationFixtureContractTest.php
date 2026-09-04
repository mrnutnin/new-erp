<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PurchasingIntegrationFixtureContractTest extends TestCase
{
    public function test_local_mysql_fixture_requires_approved_inventory_purchase_and_is_opt_in(): void
    {
        $test = file_get_contents(base_path('tests/Feature/InventoryPurchaseMySqlIntegrationReadinessTest.php'));
        $fixture = file_get_contents(base_path('tests/Support/InventoryPurchaseIntegrationFixture.php'));
        $mockup = file_get_contents(base_path('database/seeders/InventoryGlMockupSeeder.php'));

        $this->assertStringContainsString('createApprovedPurchase', $test);
        $this->assertStringContainsString('createProcurementChain', $fixture);
        $this->assertStringContainsString("'document_type' => 'INVOICE'", $fixture);
        $this->assertStringContainsString("'status' => 'APPROVED'", $fixture);
        $this->assertStringContainsString('purchase_order_line_id', $fixture);
        $this->assertStringContainsString('receiptAllocations()', $fixture);
        $this->assertStringContainsString('PurchaseThreeWayMatchGate', $fixture);
        $this->assertStringContainsString("'document_number' => 'PR-INT-'", $fixture);
        $this->assertStringContainsString("'document_number' => 'PO-INT-'", $fixture);
        $this->assertStringContainsString("'receipt_number' => 'GR-INT-'", $fixture);
        $this->assertStringContainsString("'document_number' => 'PI-INT-'", $fixture);
        $this->assertStringContainsString("'supplier_id' => null", $fixture);
        $this->assertStringContainsString("'conversion_snapshot' =>", $fixture);
        $this->assertStringContainsString("'allocated_amount' => \$receiptLine->total_cost", $fixture);
        $this->assertStringNotContainsString('PurchaseRequisition::query()->first', $fixture);
        $this->assertStringNotContainsString('PurchaseOrder::query()->first', $fixture);
        $this->assertStringNotContainsString('GoodsReceipt::query()->first', $fixture);
        $this->assertStringNotContainsString('PurchaseDocument::query()->first', $fixture);
        $this->assertStringContainsString('ERP_RUN_MYSQL_INTEGRATION', $test);
        $this->assertStringContainsString('DB::beginTransaction()', $test);
        $this->assertStringContainsString('DB::rollBack()', $test);
        $this->assertStringContainsString("'status' => 'DRAFT'", $mockup);
        $this->assertStringContainsString('intentionally creates no Posted', $mockup);
    }

    public function test_integration_fixture_requires_the_full_purchasing_identity_chain(): void
    {
        $test = file_get_contents(base_path('tests/Feature/InventoryPurchaseMySqlIntegrationReadinessTest.php'));
        $fixture = file_get_contents(base_path('tests/Support/InventoryPurchaseIntegrationFixture.php'));
        $gate = file_get_contents(base_path('app/Modules/Purchasing/Support/PurchaseThreeWayMatchGate.php'));
        $line = file_get_contents(base_path('app/Modules/Purchasing/Models/PurchaseDocumentLine.php'));
        $movementAdapter = file_get_contents(base_path('app/Modules/Wms/Support/PurchaseLineMovementAdapter.php'));
        $productionAdapter = file_get_contents(base_path('app/Modules/Wms/Services/InventoryPurchaseProductionAdapter.php'));
        foreach (['Warehouse::query()', 'createApprovedPurchase', 'wms_stock_movements', 'wms_cost_allocations', 'wms_cost_allocation_journal_lines', 'DB::beginTransaction()', 'DB::rollBack()'] as $contract) {
            $this->assertTrue(str_contains($test, $contract) || str_contains($fixture, $contract), $contract);
        }
        $this->assertStringContainsString('purchase_order_line_id', $gate);
        $this->assertStringContainsString('receipt_allocations', $gate);
        $this->assertStringContainsString('purchase_order_line_id', $line);
        $this->assertStringContainsString('receiptAllocations', $line);
        $this->assertStringContainsString('PurchaseThreeWayMatchGate', file_get_contents(base_path('app/Modules/Wms/Services/PurchaseDocumentPostingService.php')));
        foreach (['receipt_allocation_ids', 'goods_receipt_line_ids', 'conversion_snapshots', 'allocated_amount'] as $contract) {
            $this->assertStringContainsString($contract, $movementAdapter);
        }
        foreach (['PurchaseThreeWayMatchGate', 'lines.receiptAllocations.goodsReceiptLine.goodsReceipt.lines'] as $contract) {
            $this->assertStringContainsString($contract, $productionAdapter);
        }
    }

    public function test_writer_path_selects_supplier_at_po_creation_and_keeps_source_linkage(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseRequisitionController.php'));
        $service = file_get_contents(base_path('app/Modules/Purchasing/Services/PurchaseRequisitionPurchaseOrderService.php'));

        $this->assertStringContainsString("['supplier_id' => ['required', 'integer', 'min:1']]", $controller);
        $this->assertStringContainsString('createFromApproved', $controller);
        $this->assertStringContainsString("'supplier_id' => null", file_get_contents(base_path('tests/Support/InventoryPurchaseIntegrationFixture.php')));
        $this->assertStringContainsString('$selectedSupplierId = $supplierId ?: (int) $source->supplier_id;', $service);
        $this->assertStringContainsString("where('is_active', true)", $service);
        $this->assertStringContainsString("where('role', 'SUPPLIER')", $service);
        $this->assertStringContainsString("'purchase_requisition_id' => \$source->id", $service);
        $this->assertStringContainsString("'supplier_id' => \$supplier->id", $service);
        $this->assertStringContainsString("'purchase_requisition_line_id' => \$line->id", $service);
    }

    public function test_reusable_procurement_source_builder_returns_chain_ids_without_posting(): void
    {
        $builder = file_get_contents(base_path('app/Modules/Purchasing/Services/ProcurementSourceBuilder.php'));

        $this->assertStringContainsString('public function build(User $actor, string $prefix = \'INT\', ?Closure $transaction = null, bool $persistent = false)', $builder);
        $this->assertStringContainsString('DB::transaction($callback)', $builder);
        $this->assertStringContainsString('sourceHash', $builder);
        $this->assertStringContainsString('->lockForUpdate()', $builder);
        $this->assertStringContainsString('source/config hash ไม่ตรงกัน', $builder);
        $this->assertStringContainsString('OPS-SMOKE-*', $builder);
        $this->assertStringContainsString('AccountMappingService', $builder);
        $this->assertStringContainsString('GlobalSettings', $builder);
        $this->assertStringContainsString('assertIsolatedWarehouseReady', $builder);
        $this->assertStringContainsString("'supplier_id' => null", $builder);
        foreach (["'document_number' => 'PR-'.\$prefix", "'document_number' => 'PO-'.\$prefix", "'receipt_number' => 'GR-'.\$prefix", "'document_number' => 'PI-'.\$prefix", 'conversion_snapshot', 'receiptAllocations()', 'PurchaseThreeWayMatchGate', 'three_way_ready', 'receipt_allocation_id', 'source_hash', 'actor_id'] as $contract) {
            $this->assertStringContainsString($contract, $builder);
        }
        $this->assertStringNotContainsString('InventoryPurchaseProductionAdapter', $builder);
        $this->assertStringNotContainsString('JournalEntry::query()->create', $builder);
    }

    public function test_persistent_builder_validates_generated_code_lengths_before_writes(): void
    {
        $builder = file_get_contents(base_path('app/Modules/Purchasing/Services/ProcurementSourceBuilder.php'));

        $this->assertStringContainsString('strlen($prefix) > 19', $builder);
        $this->assertStringContainsString('assertGeneratedLengths', $builder);
        $this->assertStringContainsString('persistent OPS-SMOKE prefix ต้องยาวไม่เกิน 19', $builder);
        $this->assertStringContainsString('$this->assertGeneratedLengths($prefix, $suffix);', $builder);
    }

    public function test_posted_source_retry_uses_identity_integrity_before_three_way_preview(): void
    {
        $builder = file_get_contents(base_path('app/Modules/Purchasing/Services/ProcurementSourceBuilder.php'));

        $this->assertStringContainsString("if ((string) \$document->status === 'POSTED')", $builder);
        $this->assertStringContainsString('assertPostedSnapshotIntegrity', $builder);
        $this->assertStringContainsString("where('source_event', 'supplier_invoice.inventory')", $builder);
        $this->assertStringContainsString("where('status', 'POSTED')->get()", $builder);
        $this->assertStringContainsString('journalLineLinks()->count() !== 1', $builder);
    }

    public function test_optional_purchasing_fixture_has_approved_pr_po_but_keeps_receipt_draft(): void
    {
        $source = file_get_contents(base_path('database/seeders/PurchasingGoodsReceiptMockupSeeder.php'));

        $this->assertStringContainsString("'status' => 'APPROVED'", $source);
        $this->assertStringContainsString("'status' => 'DRAFT'", $source);
        $this->assertStringContainsString("'warehouse_id' => \$warehouse->id", $source);
        $this->assertStringContainsString("'supplier_id' => \$supplier->id", $source);
        $this->assertStringContainsString("'purchase_uom_id' => \$item->base_uom_id", $source);
        $this->assertStringContainsString("'stock_uom_id' => \$item->base_uom_id", $source);
        $this->assertStringContainsString("'conversion_snapshot' =>", $source);
        $this->assertStringContainsString("'purchase_order_line_id' => \$poLine->id", $source);
    }

    public function test_release_gate_separates_draft_mockup_from_dedicated_approved_fixture(): void
    {
        $qa = file_get_contents(base_path('docs/qa/inventory-gl-release-gate-local.md'));

        $this->assertStringContainsString('Dedicated Approved Purchase Fixture Builder', $qa);
        $this->assertStringContainsString('PR→PO→GR Draft/UI foundation', $qa);
        $this->assertStringContainsString('Approved Purchase Invoice สำหรับ Inventory→GL integration', $qa);
        $this->assertStringContainsString('ห้าม mark Inventory→GL ready', $qa);
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseDocumentController.php'));
        $this->assertStringContainsString('purchasing.purchase_document.inventory_posted', $controller);
        $this->assertStringContainsString('purchasing.purchase_document.inventory_reversed', $controller);
        $this->assertStringContainsString('unresolved_legacy_review=0', $qa);
        $this->assertStringContainsString('Quarantine migration prerequisite', $qa);
        $this->assertStringContainsString('Dedicated fixture builder stages', $qa);
        $this->assertStringContainsString('ห้าม mark Inventory→GL ready', $qa);
    }
}
