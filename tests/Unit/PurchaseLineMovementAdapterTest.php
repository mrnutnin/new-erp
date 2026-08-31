<?php

namespace Tests\Unit;

use App\Modules\Wms\Models\GoodsReceipt;
use App\Modules\Wms\Models\GoodsReceiptLine;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Models\PurchaseDocumentLine;
use App\Modules\Wms\Models\PurchaseDocumentReceiptAllocation;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Models\UomConversion;
use App\Modules\Wms\Support\PurchaseLineMovementAdapter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseLineMovementAdapterTest extends TestCase
{
    public function test_maps_same_base_uom_to_deterministic_receipt_intent(): void
    {
        $uom = (new Uom(['is_active' => true]))->forceFill(['id' => 2]);
        $item = (new Item(['is_active' => true, 'is_stock_item' => true, 'base_uom_id' => 2]))->forceFill(['id' => 4]);
        $line = (new PurchaseDocumentLine(['item_id' => 4, 'uom_id' => 2, 'quantity' => '2.5000', 'gross_amount' => '100.00']))
            ->forceFill(['id' => 10])->setRelation('item', $item)->setRelation('uom', $uom);
        $document = (new PurchaseDocument(['warehouse_id' => 8, 'document_date' => '2026-08-21', 'document_number' => 'PI-001', 'tax_treatment' => 'NONE_VAT']))
            ->forceFill(['id' => 9])->setRelation('lines', collect([$line]));

        $payload = PurchaseLineMovementAdapter::map($document, 10);

        $this->assertSame('2.50000000', $payload['quantity']);
        $this->assertSame($payload['quantity'], $payload['base_quantity']);
        $this->assertSame('purchase:9:line:10:receipt:0', $payload['idempotency_key']);
        $this->assertSame('supplier_invoice.inventory', $payload['metadata']['event_code']);
    }

    public function test_carries_approved_receipt_allocation_and_conversion_snapshot_into_intent(): void
    {
        $uom = (new Uom(['is_active' => true]))->forceFill(['id' => 2]);
        $item = (new Item(['is_active' => true, 'is_stock_item' => true, 'base_uom_id' => 2]))->forceFill(['id' => 4]);
        $receipt = (new GoodsReceipt(['status' => 'APPROVED']))->forceFill(['id' => 31]);
        $receiptLine = (new GoodsReceiptLine([
            'item_id' => 4, 'purchase_uom_id' => 2, 'stock_uom_id' => 2,
            'purchase_quantity' => '2.00000000', 'stock_quantity' => '2.00000000',
            'total_cost' => '100.00', 'conversion_snapshot' => ['factor' => '1.00000000'],
        ]))->forceFill(['id' => 32])->setRelation('goodsReceipt', $receipt);
        $allocation = (new PurchaseDocumentReceiptAllocation([
            'allocated_quantity' => '2.00000000', 'allocated_amount' => '100.00',
        ]))->forceFill(['id' => 33])->setRelation('goodsReceiptLine', $receiptLine);
        $line = (new PurchaseDocumentLine([
            'item_id' => 4, 'uom_id' => 2, 'quantity' => '2.0000', 'gross_amount' => '100.00',
        ]))->forceFill(['id' => 10])->setRelation('item', $item)->setRelation('uom', $uom)->setRelation('receiptAllocations', collect([$allocation]));
        $document = (new PurchaseDocument(['warehouse_id' => 8, 'document_date' => '2026-08-21', 'document_number' => 'PI-001', 'tax_treatment' => 'NONE_VAT']))
            ->forceFill(['id' => 9])->setRelation('lines', collect([$line]));

        $payload = PurchaseLineMovementAdapter::map($document, 10);

        $this->assertSame('2.00000000', $payload['base_quantity']);
        $this->assertSame('100.00', $payload['metadata']['allocated_amount']);
        $this->assertSame([33], $payload['metadata']['receipt_allocation_ids']);
        $this->assertSame([32], $payload['metadata']['goods_receipt_line_ids']);
        $this->assertSame(['factor' => '1.00000000'], $payload['metadata']['conversion_snapshots'][0]);
    }

    public function test_rejects_unresolved_uom_conversion_instead_of_guessing_base_quantity(): void
    {
        $uom = (new Uom(['is_active' => true]))->forceFill(['id' => 2]);
        $base = (new Uom(['is_active' => true]))->forceFill(['id' => 3])->setRelation('fromConversions', collect());
        $item = (new Item(['is_active' => true, 'is_stock_item' => true, 'base_uom_id' => 3]))->forceFill(['id' => 4])->setRelation('baseUom', $base);
        $line = (new PurchaseDocumentLine(['item_id' => 4, 'uom_id' => 2, 'quantity' => '2', 'gross_amount' => '100.00']))
            ->forceFill(['id' => 10])->setRelation('item', $item)->setRelation('uom', $uom->setRelation('fromConversions', collect()));
        $document = (new PurchaseDocument(['warehouse_id' => 8, 'document_date' => '2026-08-21']))
            ->forceFill(['id' => 9])->setRelation('lines', collect([$line]));

        $this->expectException(ValidationException::class);
        PurchaseLineMovementAdapter::map($document, 10);
    }

    public function test_applies_forward_conversion_factor(): void
    {
        [$document, $line] = $this->conversionFixture(2, 3, '12.5');
        $this->assertSame('25.00000000', PurchaseLineMovementAdapter::map($document, $line->id)['base_quantity']);
    }

    public function test_applies_reverse_conversion_by_division(): void
    {
        [$document, $line] = $this->conversionFixture(3, 2, '2', false, false);
        $this->assertSame('1.00000000', PurchaseLineMovementAdapter::map($document, $line->id)['base_quantity']);
    }

    public function test_rejects_both_forward_and_reverse_conversions(): void
    {
        [$document, $line] = $this->conversionFixture(2, 3, '2', true);
        $this->expectException(ValidationException::class);
        PurchaseLineMovementAdapter::map($document, $line->id);
    }

    public function test_rejects_missing_or_inactive_source_line(): void
    {
        $document = (new PurchaseDocument(['warehouse_id' => 8, 'document_date' => '2026-08-21']))
            ->forceFill(['id' => 9])->setRelation('lines', collect());

        $this->expectException(ValidationException::class);
        PurchaseLineMovementAdapter::map($document, 10);
    }

    private function conversionFixture(int $lineUomId, int $baseUomId, string $factor, bool $both = false, bool $forward = true): array
    {
        $conversion = (new UomConversion(['from_uom_id' => $lineUomId, 'to_uom_id' => $baseUomId, 'factor' => $factor]))->forceFill(['id' => 1]);
        $reverse = (new UomConversion(['from_uom_id' => $baseUomId, 'to_uom_id' => $lineUomId, 'factor' => $factor]))->forceFill(['id' => 2]);
        $lineUom = (new Uom(['is_active' => true]))->forceFill(['id' => $lineUomId])->setRelation('fromConversions', collect($forward ? [$conversion] : []));
        $baseUom = (new Uom(['is_active' => true]))->forceFill(['id' => $baseUomId])->setRelation('fromConversions', collect(($both || ! $forward) ? [$reverse] : []));
        $item = (new Item(['is_active' => true, 'is_stock_item' => true, 'base_uom_id' => $baseUomId]))->forceFill(['id' => 4])->setRelation('baseUom', $baseUom);
        $line = (new PurchaseDocumentLine(['item_id' => 4, 'uom_id' => $lineUomId, 'quantity' => '2', 'gross_amount' => '100.00']))->forceFill(['id' => 10])->setRelation('item', $item)->setRelation('uom', $lineUom);
        $document = (new PurchaseDocument(['warehouse_id' => 8, 'document_date' => '2026-08-21', 'document_number' => 'PI-001', 'tax_treatment' => 'NONE_VAT']))->forceFill(['id' => 9])->setRelation('lines', collect([$line]));

        return [$document, $line];
    }
}
