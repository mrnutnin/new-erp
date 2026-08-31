<?php

namespace Tests\Unit;

use App\Modules\Accounting\Models\Account;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Models\PurchaseDocumentLine;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Services\InventoryPurchasePostingService;
use App\Modules\Wms\Support\InventoryPurchasePostingContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryPurchasePostingServiceTest extends TestCase
{
    public function test_preflight_is_closed_even_when_payload_is_valid(): void
    {
        [$document, $ap] = $this->fixtures();
        $result = (new InventoryPurchasePostingService(new InventoryPurchasePostingContract))->preflight($document, $ap);

        $this->assertFalse($result['ready']);
        $this->assertFalse($result['creates_journal']);
        $this->assertContains('reconciliation_zero_gate', $result['blockers']);
        $this->assertSame('supplier_invoice.inventory', $result['payload']['event_code']);
    }

    public function test_preflight_declares_same_payload_reuse_policy(): void
    {
        [$document, $ap] = $this->fixtures();
        $result = (new InventoryPurchasePostingService(new InventoryPurchasePostingContract))->preflight($document, $ap);

        $this->assertSame('REUSE', $result['plan']['idempotency']['same_key_same_hash']);
        $this->assertSame('purchase:9:supplier_invoice.inventory:revision:0', $result['plan']['idempotency_key']);
    }

    public function test_preflight_declares_different_payload_reject_and_rollback_gates(): void
    {
        [$document, $ap] = $this->fixtures();
        $result = (new InventoryPurchasePostingService(new InventoryPurchasePostingContract))->preflight($document, $ap);

        $this->assertSame('REJECT', $result['plan']['idempotency']['same_key_different_hash']);
        $this->assertContains('reconciliation_zero', $result['plan']['rollback_gates']);
        $this->assertFalse($result['creates_journal']);
    }

    public function test_feature_enabled_preflight_opens_only_the_none_vat_inventory_contract(): void
    {
        [$document, $ap] = $this->fixtures();
        $result = (new InventoryPurchasePostingService(new InventoryPurchasePostingContract))->preflight($document, $ap, true);

        $this->assertTrue($result['ready']);
        $this->assertTrue($result['creates_journal']);
        $this->assertSame('supplier_invoice.inventory', $result['payload']['event_code']);
        $this->assertSame('ITEM', $result['payload']['lines'][0]['subledger_type']);
        $this->assertSame('SUPPLIER', $result['payload']['lines'][1]['subledger_type']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_post_gate_rejects_any_closed_preflight(): void
    {
        $this->expectException(ValidationException::class);
        (new InventoryPurchasePostingService(new InventoryPurchasePostingContract))->assertPostGate([
            'ready' => false, 'creates_journal' => false, 'blockers' => ['atomic_linkage'],
        ]);
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
