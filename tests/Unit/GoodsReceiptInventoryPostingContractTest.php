<?php

namespace Tests\Unit;

use App\Modules\Wms\Models\GoodsReceipt;
use App\Modules\Wms\Models\GoodsReceiptLine;
use App\Modules\Wms\Support\GoodsReceiptInventoryPostingContract;
use App\Modules\Wms\Support\GoodsReceiptMovementAdapter;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class GoodsReceiptInventoryPostingContractTest extends TestCase
{
    public function test_approved_snapshot_maps_to_draft_movement_intent_only(): void
    {
        $receipt = new GoodsReceipt(['id' => 9, 'warehouse_id' => 2, 'purchase_order_id' => 7, 'receipt_number' => 'GR-001', 'business_date' => '2026-08-22', 'status' => 'APPROVED']);
        $receipt->setAttribute('id', 9);
        $receipt->exists = true;
        $line = new GoodsReceiptLine(['id' => 10, 'goods_receipt_id' => 9, 'purchase_order_line_id' => 4, 'item_id' => 3, 'purchase_uom_id' => 5, 'stock_uom_id' => 6, 'purchase_quantity' => '2.00000000', 'factor' => '10.00000000', 'stock_quantity' => '20.00000000', 'total_cost' => '100.00000000', 'stock_unit_cost' => '5.00000000', 'rounding_delta' => '0.00000000', 'conversion_snapshot' => ['purchase_uom_id' => 5, 'stock_uom_id' => 6, 'factor' => '10.00000000', 'business_date' => '2026-08-22']]);
        $line->setAttribute('id', 10);
        $line->exists = true;
        $line->setRelation('goodsReceipt', $receipt);
        $receipt->setRelation('lines', collect([$line]));

        $intent = GoodsReceiptInventoryPostingContract::movementIntents($receipt)[0];

        $this->assertSame('DRAFT', $intent['status']);
        $this->assertSame('GOODS_RECEIPT', $intent['source_type']);
        $this->assertSame('20.00000000', $intent['base_quantity']);
        $this->assertSame('goods-receipt:9:line:10', $intent['idempotency_key']);
        $this->assertSame('5.00000000', $intent['metadata']['unit_cost']);
    }

    public function test_unapproved_or_incomplete_snapshot_is_blocked(): void
    {
        $receipt = new GoodsReceipt(['warehouse_id' => 2, 'purchase_order_id' => 7, 'status' => 'DRAFT']);
        $this->expectException(ValidationException::class);
        GoodsReceiptInventoryPostingContract::movementIntents($receipt);
    }

    public function test_movement_adapter_normalizes_snapshot_without_writing(): void
    {
        $receipt = new GoodsReceipt(['warehouse_id' => 2, 'purchase_order_id' => 7, 'receipt_number' => 'GR-001', 'business_date' => '2026-08-22', 'status' => 'APPROVED']);
        $receipt->setAttribute('id', 9);
        $receipt->exists = true;
        $line = new GoodsReceiptLine(['goods_receipt_id' => 9, 'purchase_order_line_id' => 4, 'item_id' => 3, 'purchase_uom_id' => 5, 'stock_uom_id' => 6, 'purchase_quantity' => '2.00000000', 'factor' => '10.00000000', 'stock_quantity' => '20.00000000', 'total_cost' => '100.00000000', 'stock_unit_cost' => '5.00000000', 'rounding_delta' => '0.00000000', 'conversion_snapshot' => ['purchase_uom_id' => 5, 'stock_uom_id' => 6, 'factor' => '10.00000000', 'business_date' => '2026-08-22']]);
        $line->setAttribute('id', 10);
        $line->exists = true;
        $receipt->setRelation('lines', collect([$line]));
        $line->setRelation('goodsReceipt', $receipt);

        $intent = GoodsReceiptMovementAdapter::map($receipt)[0];

        $this->assertSame('DRAFT', $intent['status']);
        $this->assertSame(6, $intent['uom_id']);
        $this->assertSame('GOODS_RECEIPT', $intent['source_type']);
    }
}
