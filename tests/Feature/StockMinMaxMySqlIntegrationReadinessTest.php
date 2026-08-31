<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockPolicy;
use App\Modules\Wms\Models\UomConversion;
use App\Modules\Wms\Services\StockMinMaxAlertService;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Local MySQL-only Min/Max policy, reserved and open-PO readiness check. */
final class StockMinMaxMySqlIntegrationReadinessTest extends TestCase
{
    use DatabaseTransactions;

    public function test_alert_subtracts_reserved_and_approved_open_po(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $warehouse = Warehouse::query()->where('is_active', true)->first();
        $item = Item::query()->where('is_active', true)->where('is_stock_item', true)->first();
        $supplier = DB::table('parties')->first();
        if (! $warehouse || ! $item || ! $supplier) {
            $this->markTestSkipped('ต้องมี Warehouse/Stock Item/Supplier fixture ใน local MySQL');
        }

        $uomId = (int) $item->base_uom_id;
        StockPolicy::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'item_id' => $item->id],
            ['min_quantity' => 80, 'max_quantity' => 100, 'reorder_quantity' => 20, 'is_active' => true, 'created_by' => User::query()->first()?->id]
        );
        StockBalance::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'uom_id' => $uomId],
            ['on_hand' => 50, 'reserved' => 10, 'available' => 40, 'inventory_value' => 0, 'average_unit_cost' => 0]
        );
        $poId = DB::table('purchase_orders')->insertGetId([
            'warehouse_id' => $warehouse->id, 'supplier_id' => $supplier->id, 'supplier_code' => $supplier->code, 'supplier_name' => $supplier->name,
            'document_number' => 'MM-PO-'.Str::upper(Str::random(8)), 'document_date' => now()->toDateString(), 'subtotal' => 0, 'total_amount' => 0,
            'status' => 'APPROVED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lineId = DB::table('purchase_order_lines')->insertGetId([
            'purchase_order_id' => $poId, 'line_number' => 1, 'item_id' => $item->id, 'uom_id' => $uomId,
            'description' => 'Min/Max integration fixture', 'quantity' => 30, 'unit_price' => 0, 'line_total' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $alert = app(StockMinMaxAlertService::class)->alerts($warehouse)->firstWhere('item_id', $item->id);

        $this->assertNotNull($alert);
        $this->assertSame('50.00000000', $alert['on_hand']);
        $this->assertSame('10.00000000', $alert['reserved']);
        $this->assertSame('40.00000000', $alert['available']);
        $this->assertSame('30.00000000', $alert['open_po']);
        $this->assertSame('30.00000000', $alert['recommended']);
        $this->assertSame(0, DB::table('goods_receipt_lines')->where('purchase_order_line_id', $lineId)->count());
    }

    public function test_alert_converts_open_po_to_item_stock_uom(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $warehouse = Warehouse::query()->where('is_active', true)->first();
        $conversion = UomConversion::query()->whereColumn('from_uom_id', '<>', 'to_uom_id')->where('effective_from', '<=', now()->toDateString())->where(function ($query): void {
            $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString());
        })->first();
        $item = $conversion ? Item::query()->where('is_active', true)->where('is_stock_item', true)->where('base_uom_id', $conversion->to_uom_id)->first() : null;
        $supplier = DB::table('parties')->first();
        if (! $warehouse || ! $conversion || ! $item || ! $supplier) {
            $this->markTestSkipped('ต้องมี Warehouse/Stock Item/Supplier/UOM conversion fixture ใน local MySQL');
        }

        $uomId = (int) $item->base_uom_id;
        StockPolicy::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'item_id' => $item->id],
            ['min_quantity' => 80, 'max_quantity' => 100, 'reorder_quantity' => 20, 'is_active' => true, 'created_by' => User::query()->first()?->id]
        );
        StockBalance::query()->updateOrCreate(
            ['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'uom_id' => $uomId],
            ['on_hand' => 40, 'reserved' => 0, 'available' => 40, 'inventory_value' => 0, 'average_unit_cost' => 0]
        );
        $service = app(StockMinMaxAlertService::class);
        $before = $service->alerts($warehouse)->firstWhere('item_id', $item->id);
        $poId = DB::table('purchase_orders')->insertGetId([
            'warehouse_id' => $warehouse->id, 'supplier_id' => $supplier->id, 'supplier_code' => $supplier->code, 'supplier_name' => $supplier->name,
            'document_number' => 'MM-PO-CONV-'.Str::upper(Str::random(8)), 'document_date' => now()->toDateString(), 'subtotal' => 0, 'total_amount' => 0,
            'status' => 'APPROVED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_order_lines')->insert([
            'purchase_order_id' => $poId, 'line_number' => 1, 'item_id' => $item->id, 'uom_id' => $conversion->from_uom_id,
            'description' => 'Min/Max conversion integration fixture', 'quantity' => 3, 'unit_price' => 0, 'line_total' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $alert = $service->alerts($warehouse)->firstWhere('item_id', $item->id);

        $this->assertNotNull($alert);
        $this->assertNotNull($before);
        $delta = BigDecimal::of($alert['open_po'])->minus($before['open_po']);
        $this->assertSame((string) BigDecimal::of('3')->multipliedBy((string) $conversion->factor)->toScale(8), $delta->__toString());
    }

    public function test_alert_does_not_include_open_po_from_another_warehouse(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $warehouses = Warehouse::query()->where('is_active', true)->orderBy('id')->limit(2)->get();
        $item = Item::query()->where('is_active', true)->where('is_stock_item', true)->first();
        $supplier = DB::table('parties')->first();
        if ($warehouses->count() < 2 || ! $item || ! $supplier) {
            $this->markTestSkipped('ต้องมี Warehouse อย่างน้อยสองแห่ง/Stock Item/Supplier fixture ใน local MySQL');
        }

        $selectedWarehouse = $warehouses->first();
        $otherWarehouse = $warehouses->last();
        $uomId = (int) $item->base_uom_id;
        StockPolicy::query()->updateOrCreate(
            ['warehouse_id' => $selectedWarehouse->id, 'item_id' => $item->id],
            ['min_quantity' => 999, 'max_quantity' => 1000, 'reorder_quantity' => 1, 'is_active' => true, 'created_by' => User::query()->first()?->id]
        );
        StockBalance::query()->updateOrCreate(
            ['warehouse_id' => $selectedWarehouse->id, 'item_id' => $item->id, 'uom_id' => $uomId],
            ['on_hand' => 0, 'reserved' => 0, 'available' => 0, 'inventory_value' => 0, 'average_unit_cost' => 0]
        );
        $service = app(StockMinMaxAlertService::class);
        $before = $service->alerts($selectedWarehouse)->firstWhere('item_id', $item->id);
        $poId = DB::table('purchase_orders')->insertGetId([
            'warehouse_id' => $otherWarehouse->id, 'supplier_id' => $supplier->id, 'supplier_code' => $supplier->code, 'supplier_name' => $supplier->name,
            'document_number' => 'MM-PO-SCOPE-'.Str::upper(Str::random(8)), 'document_date' => now()->toDateString(), 'subtotal' => 0, 'total_amount' => 0,
            'status' => 'APPROVED', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('purchase_order_lines')->insert([
            'purchase_order_id' => $poId, 'line_number' => 1, 'item_id' => $item->id, 'uom_id' => $uomId,
            'description' => 'Min/Max warehouse scope fixture', 'quantity' => 999, 'unit_price' => 0, 'line_total' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $after = $service->alerts($selectedWarehouse)->firstWhere('item_id', $item->id);

        $this->assertNotNull($before);
        $this->assertNotNull($after);
        $this->assertSame($before['open_po'], $after['open_po']);
    }
}
