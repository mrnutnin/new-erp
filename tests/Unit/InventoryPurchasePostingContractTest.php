<?php

namespace Tests\Unit;

use App\Modules\Accounting\Models\Account;
use App\Modules\Wms\Models\Item;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Models\PurchaseDocumentLine;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Support\InventoryPurchasePostingContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryPurchasePostingContractTest extends TestCase
{
    public function test_builds_inventory_purchase_payload_without_posting(): void
    {
        [$document, $ap] = $this->fixtures();
        $payload = (new InventoryPurchasePostingContract)->payload($document, $ap);

        $this->assertSame('supplier_invoice.inventory', $payload['event_code']);
        $this->assertSame('ITEM', $payload['lines'][0]['subledger_type']);
        $this->assertSame('SUPPLIER', $payload['lines'][1]['subledger_type']);
        $this->assertSame('100.00', $payload['lines'][0]['debit']);
        $this->assertSame('100.00', $payload['lines'][1]['credit']);
    }

    public function test_it_rejects_vat_or_non_stock_lines(): void
    {
        [$document, $ap] = $this->fixtures();
        $document->tax_treatment = 'VAT_IN';
        $this->expectException(ValidationException::class);
        (new InventoryPurchasePostingContract)->payload($document, $ap);
    }

    public function test_atomic_plan_is_dry_run_and_has_explicit_lock_order(): void
    {
        [$document] = $this->fixtures();
        $plan = (new InventoryPurchasePostingContract)->atomicPlan($document);

        $this->assertFalse($plan['creates_journal']);
        $this->assertSame(['purchase_document', 'journal_book', 'fiscal_period', 'stock_movement', 'cost_allocations', 'cost_layers', 'stock_balance'], $plan['lock_order']);
        $this->assertStringContainsString('supplier_invoice.inventory', $plan['idempotency_key']);
        $this->assertSame('REUSE', $plan['idempotency']['same_key_same_hash']);
        $this->assertContains('reconciliation_zero', $plan['rollback_gates']);
        $this->assertFalse($plan['schema_gaps']['receipt_line_reference']);
        $this->assertFalse($plan['schema_gaps']['allocation_journal_line_linkage']);
    }

    private function fixtures(): array
    {
        $item = (new Item(['is_active' => true, 'is_stock_item' => true]))->forceFill(['id' => 4]);
        $inventory = (new Account(['is_active' => true, 'is_postable' => true, 'control_account_type' => 'INVENTORY']))->forceFill(['id' => 20]);
        $item->setRelation('inventoryAccount', $inventory);
        $uom = (new Uom(['is_active' => true]))->forceFill(['id' => 2]);
        $line = (new PurchaseDocumentLine([
            'purchase_document_id' => 9, 'item_id' => 4, 'uom_id' => 2, 'account_id' => 20,
            'description' => 'สินค้า', 'gross_amount' => '100.00',
        ]))->forceFill(['id' => 10])->setRelation('item', $item)->setRelation('uom', $uom);
        $document = (new PurchaseDocument([
            'document_type' => 'INVOICE', 'tax_treatment' => 'NONE_VAT', 'rounding_amount' => '0.00',
            'document_number' => 'PI-001', 'document_date' => '2026-08-21', 'supplier_id' => 7,
            'gross_amount' => '100.00',
        ]))->forceFill(['id' => 9])->setRelation('lines', collect([$line]));
        $ap = (new Account(['is_active' => true, 'is_postable' => true, 'control_account_type' => 'AP']))->forceFill(['id' => 30]);

        return [$document, $ap];
    }
}
