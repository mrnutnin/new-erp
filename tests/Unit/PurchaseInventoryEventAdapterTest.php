<?php

namespace Tests\Unit;

use App\Modules\Accounting\Models\Account;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Models\PurchaseDocumentLine;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Services\InventoryPurchasePostingService;
use App\Modules\Wms\Services\PurchaseInventoryEventAdapter;
use App\Modules\Wms\Support\InventoryPurchasePostingContract;
use Tests\TestCase;

class PurchaseInventoryEventAdapterTest extends TestCase
{
    public function test_expense_event_is_not_promoted_without_explicit_inventory_readiness(): void
    {
        [$document, $ap] = $this->fixtures();
        $result = $this->adapter()->preflight($document, $ap, true, true, false);

        $this->assertFalse($result['accepted']);
        $this->assertSame('supplier_invoice.expense', $result['event_code']);
        $this->assertSame('supplier_invoice.inventory', $result['candidate_event_code']);
        $this->assertContains('receipt_readiness_required', $result['blockers']);
    }

    public function test_inventory_candidate_remains_closed_after_all_explicit_flags(): void
    {
        [$document, $ap] = $this->fixtures();
        $result = $this->adapter()->preflight($document, $ap, true, true, true);

        $this->assertFalse($result['accepted']);
        $this->assertSame('supplier_invoice.inventory', $result['event_code']);
        $this->assertSame('PURCHASING', $result['source_identity']['source_type']);
        $this->assertSame('9', $result['source_identity']['source_id']);
        $this->assertNotEmpty($result['payload_hash']);
        $this->assertContains('inventory_purchase_event_wiring', $result['blockers']);
    }

    public function test_same_source_produces_deterministic_identity_and_payload_hash(): void
    {
        [$document, $ap] = $this->fixtures();
        $adapter = $this->adapter();
        $first = $adapter->preflight($document, $ap, true, true, true);
        $second = $adapter->preflight($document, $ap, true, true, true);

        $this->assertSame($first['source_identity'], $second['source_identity']);
        $this->assertSame($first['payload_hash'], $second['payload_hash']);
    }

    private function adapter(): PurchaseInventoryEventAdapter
    {
        return new PurchaseInventoryEventAdapter(
            new InventoryPurchasePostingService(new InventoryPurchasePostingContract),
        );
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
            'document_type' => 'INVOICE', 'tax_treatment' => 'NONE_VAT', 'rounding_amount' => '0.00',
            'document_number' => 'PI-001', 'document_date' => '2026-08-21', 'supplier_id' => 7, 'gross_amount' => '100.00',
        ]))->forceFill(['id' => 9])->setRelation('lines', collect([$line]));
        $ap = (new Account(['is_active' => true, 'is_postable' => true, 'control_account_type' => 'AP']))->forceFill(['id' => 30]);

        return [$document, $ap];
    }
}
