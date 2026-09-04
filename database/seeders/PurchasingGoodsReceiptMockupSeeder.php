<?php

namespace Database\Seeders;

use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Wms\Models\Item;
use App\Modules\Purchasing\Models\PurchaseOrder;
use App\Modules\Purchasing\Models\PurchaseRequisition;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Optional local-only fixture. It is intentionally not called by DatabaseSeeder:
 * no Journal, Stock Movement, Cost Layer or Inventory Post is created here.
 */
class PurchasingGoodsReceiptMockupSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('username', 'admin')->firstOrFail();
        $warehouse = Warehouse::query()->where('code', 'HQ-WH')->firstOrFail();
        $supplier = Party::query()->where('code', 'SUP-001')->where('is_active', true)->firstOrFail();
        $item = Item::query()->where('is_active', true)->where('is_stock_item', true)->with('baseUom')->orderBy('id')->first();
        if (! $item || ! $item->base_uom_id) {
            $this->command?->warn('ข้าม PurchasingGoodsReceiptMockupSeeder: ยังไม่มีสินค้า stock ที่มี Base UOM');

            return;
        }

        DB::transaction(function () use ($user, $warehouse, $supplier, $item): void {
            $pr = PurchaseRequisition::query()->updateOrCreate(['document_number' => 'PR-MOCK-001'], [
                'warehouse_id' => $warehouse->id, 'document_date' => '2026-08-22', 'supplier_id' => $supplier->id,
                'description' => 'Mockup PR → PO → Goods Receipt Draft (partial)', 'status' => 'APPROVED',
                'approved_by' => $user->id, 'approved_at' => now(), 'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
            $prLine = $pr->lines()->updateOrCreate(['line_number' => 1], [
                'item_id' => $item->id, 'uom_id' => $item->base_uom_id, 'quantity' => '10.0000', 'description' => 'Mock สินค้าสำหรับทดสอบรับบางส่วน',
            ]);
            $po = PurchaseOrder::query()->updateOrCreate(['document_number' => 'PO-MOCK-001'], [
                'warehouse_id' => $warehouse->id, 'purchase_requisition_id' => $pr->id, 'supplier_id' => $supplier->id,
                'supplier_code' => $supplier->code, 'supplier_name' => $supplier->name, 'document_date' => '2026-08-22',
                'subtotal' => '1000.00', 'total_amount' => '1000.00', 'status' => 'APPROVED', 'approved_by' => $user->id,
                'approved_at' => now(), 'description' => 'Mock PO จาก PR-MOCK-001', 'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
            $poLine = $po->lines()->updateOrCreate(['line_number' => 1], [
                'purchase_requisition_line_id' => $prLine->id, 'item_id' => $item->id, 'uom_id' => $item->base_uom_id,
                'description' => 'Mock สินค้าสำหรับรับบางส่วน', 'quantity' => '10.0000', 'unit_price' => '100.0000', 'line_total' => '1000.00',
            ]);
            $receipt = GoodsReceipt::query()->updateOrCreate(['idempotency_key' => 'gr-mock-pr-po-001'], [
                'warehouse_id' => $warehouse->id, 'purchase_order_id' => $po->id, 'supplier_id' => $supplier->id,
                'receipt_number' => 'GR-MOCK-001', 'business_date' => '2026-08-22', 'status' => 'DRAFT',
                'description' => 'Mock รับบางส่วน 4/10; ยังไม่สร้าง Movement/Cost Layer/Journal', 'created_by' => $user->id, 'updated_by' => $user->id,
            ]);
            $receipt->lines()->updateOrCreate(['purchase_order_line_id' => $poLine->id], [
                'item_id' => $item->id, 'purchase_uom_id' => $item->base_uom_id, 'stock_uom_id' => $item->base_uom_id,
                'purchase_quantity' => '4.00000000', 'factor' => '1.00000000', 'stock_quantity' => '4.00000000',
                'total_cost' => '400.00000000', 'stock_unit_cost' => '100.00000000', 'rounding_delta' => '0.00000000',
                'conversion_snapshot' => ['purchase_uom_id' => $item->base_uom_id, 'stock_uom_id' => $item->base_uom_id, 'factor' => '1.00000000', 'conversion_id' => null, 'business_date' => '2026-08-22'],
            ]);
        });
    }
}
