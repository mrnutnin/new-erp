<?php

namespace Tests\Unit;

use App\Modules\Accounting\Models\Account;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Models\PurchaseDocumentLine;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Support\InventoryPurchasePostingContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseReceiptNoneVatGlReconciliationContractTest extends TestCase
{
    public function test_none_vat_payload_is_inventory_debit_and_supplier_ap_credit(): void
    {
        [$document, $ap] = $this->fixtures();

        $payload = (new InventoryPurchasePostingContract)->payload($document, $ap);

        $this->assertSame('PURCHASING', $payload['source_type']);
        $this->assertSame('supplier_invoice.inventory', $payload['event_code']);
        $this->assertSame([
            'account_id' => 20,
            'subledger_type' => 'ITEM',
            'subledger_id' => '4',
            'debit' => '100.00',
            'credit' => '0.00',
        ], array_intersect_key($payload['lines'][0], array_flip(['account_id', 'subledger_type', 'subledger_id', 'debit', 'credit'])));
        $this->assertSame([
            'account_id' => 30,
            'subledger_type' => 'SUPPLIER',
            'subledger_id' => '7',
            'debit' => '0.00',
            'credit' => '100.00',
        ], array_intersect_key($payload['lines'][1], array_flip(['account_id', 'subledger_type', 'subledger_id', 'debit', 'credit'])));
    }

    public function test_plan_freezes_reconciliation_identity_retry_and_atomic_rollback_gates(): void
    {
        [$document] = $this->fixtures();

        $plan = (new InventoryPurchasePostingContract)->atomicPlan($document);

        $this->assertSame('purchase:9:supplier_invoice.inventory:revision:0', $plan['idempotency_key']);
        $this->assertSame([
            'purchase_document', 'journal_book', 'fiscal_period', 'stock_movement',
            'cost_allocations', 'cost_layers', 'stock_balance',
        ], $plan['lock_order']);
        $this->assertSame([
            'allocation_value_equals_inventory_journal',
            'movement_quantity_equals_allocation_quantity',
            'allocation_has_immutable_journal_line_link',
            'no_pending_or_unlinked_allocation',
        ], $plan['reconciliation_gates']);
        $this->assertSame([
            'purchase_document_status', 'journal_identity', 'movement_source_identity',
            'allocation_journal_linkage', 'reconciliation_zero',
        ], $plan['rollback_gates']);
        $this->assertSame('REUSE', $plan['idempotency']['same_key_same_hash']);
        $this->assertSame('REJECT', $plan['idempotency']['same_key_different_hash']);
    }

    public function test_inventory_purchase_contract_rejects_vat_in(): void
    {
        [$document] = $this->fixtures();
        $document->tax_treatment = 'VAT_IN';

        $this->expectException(ValidationException::class);
        (new InventoryPurchasePostingContract)->atomicPlan($document);
    }

    public function test_inventory_purchase_contract_rejects_nonzero_rounding(): void
    {
        [$document] = $this->fixtures();
        $document->tax_treatment = 'NONE_VAT';
        $document->rounding_amount = '0.01';

        $this->expectException(ValidationException::class);
        (new InventoryPurchasePostingContract)->atomicPlan($document);
    }

    private function fixtures(): array
    {
        $inventory = (new Account(['is_active' => true, 'is_postable' => true, 'control_account_type' => 'INVENTORY']))->forceFill(['id' => 20]);
        $item = (new Item(['is_active' => true, 'is_stock_item' => true]))->forceFill(['id' => 4])->setRelation('inventoryAccount', $inventory);
        $uom = (new Uom(['is_active' => true]))->forceFill(['id' => 2]);
        $line = (new PurchaseDocumentLine([
            'purchase_document_id' => 9, 'item_id' => 4, 'uom_id' => 2, 'account_id' => 20,
            'description' => 'สินค้า', 'gross_amount' => '100.00',
        ]))->forceFill(['id' => 10])->setRelation('item', $item)->setRelation('uom', $uom);
        $document = (new PurchaseDocument([
            'warehouse_id' => 7, 'document_type' => 'INVOICE', 'tax_treatment' => 'NONE_VAT',
            'rounding_amount' => '0.00', 'document_number' => 'PI-001', 'document_date' => '2026-08-21',
            'supplier_id' => 7, 'gross_amount' => '100.00',
        ]))->forceFill(['id' => 9])->setRelation('lines', collect([$line]));
        $ap = (new Account(['is_active' => true, 'is_postable' => true, 'control_account_type' => 'AP']))->forceFill(['id' => 30]);

        return [$document, $ap];
    }
}
