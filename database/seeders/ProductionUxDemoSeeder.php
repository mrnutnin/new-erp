<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Production\Models\Bom;
use App\Modules\Production\Models\BomRevision;
use App\Modules\Production\Models\BomLine;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderMaterial;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ProductionUxDemoSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->orderBy('id')->first();
        $warehouse = Warehouse::query()->where('is_active', true)->with('branch')->orderBy('id')->first();
        if (! $user || ! $warehouse) {
            throw new RuntimeException('ต้องมี User และ Warehouse ก่อน seed Production UX demo');
        }
        $uom = Uom::query()->where('is_active', true)->orderBy('id')->first()
            ?: Uom::query()->create(['code' => 'UX', 'name' => 'หน่วยตัวอย่าง', 'decimal_places' => 2, 'is_active' => true, 'created_by' => $user->id]);
        $categoryId = DB::table('wms_item_categories')->where('code', 'UX-DEMO')->value('id')
            ?: DB::table('wms_item_categories')->insertGetId(['code' => 'UX-DEMO', 'name' => 'Production UX Demo', 'is_active' => true, 'created_by' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        $item = Item::query()->where('code', 'UX-DEMO-FG')->first()
            ?: Item::query()->create(['category_id' => $categoryId, 'code' => 'UX-DEMO-FG', 'name' => 'สินค้าตัวอย่าง Production UX', 'item_type' => 'GOODS', 'base_uom' => $uom->code, 'base_uom_id' => $uom->id, 'is_stock_item' => true, 'is_active' => true, 'created_by' => $user->id]);
        $component = Item::query()->where('code', 'UX-DEMO-RM')->first()
            ?: Item::query()->create(['category_id' => $categoryId, 'code' => 'UX-DEMO-RM', 'name' => 'วัตถุดิบตัวอย่าง Production UX', 'item_type' => 'GOODS', 'base_uom' => $uom->code, 'base_uom_id' => $uom->id, 'is_stock_item' => true, 'is_active' => true, 'created_by' => $user->id]);
        $bom = Bom::query()->firstOrCreate(['branch_id' => $warehouse->branch_id, 'code' => 'UX-DEMO-BOM'], ['name' => 'BOM ตัวอย่าง Production UX', 'finished_item_id' => $item->id, 'base_uom_id' => $uom->id, 'is_active' => true, 'created_by' => $user->id]);
        $revision = BomRevision::query()->firstOrCreate(['bom_id' => $bom->id, 'revision_number' => 1], ['status' => 'ACTIVE', 'effective_from' => today(), 'activated_at' => now(), 'activated_by' => $user->id, 'created_by' => $user->id]);
        $bomLine = BomLine::query()->firstOrCreate(['bom_revision_id' => $revision->id, 'line_number' => 1], ['component_item_id' => $component->id, 'uom_id' => $uom->id, 'quantity' => 2, 'notes' => 'วัตถุดิบหลักสำหรับ Demo']);

        foreach ([
            ['01', 'DRAFT', 'รอเตรียมข้อมูล', -2, 2],
            ['02', 'RELEASED', 'พร้อมเริ่มผลิต', 0, 1],
            ['03', 'IN_PROGRESS', 'กำลังผลิต', -1, 3],
            ['04', 'IN_PROGRESS', 'งานเร่งด่วน', 2, 5],
            ['05', 'COMPLETED', 'ผลิตเสร็จแล้ว', -5, 2],
            ['06', 'CANCELLED', 'ยกเลิกเพื่อทดสอบ badge', 4, 1],
        ] as [$suffix, $status, $note, $startOffset, $quantity]) {
            $order = ProductionOrder::query()->updateOrCreate(
                ['document_number' => 'UX-DEMO-'.$suffix],
                [
                    'branch_id' => $warehouse->branch_id,
                    'issue_warehouse_id' => $warehouse->id,
                    'receipt_warehouse_id' => $warehouse->id,
                    'order_type' => 'MAKE_TO_STOCK',
                    'status' => $status,
                    'finished_item_id' => $item->id,
                    'uom_id' => $uom->id,
                    'planned_quantity' => $quantity,
                    'completed_quantity' => $status === 'COMPLETED' ? $quantity : 0,
                    'reject_quantity' => 0,
                    'bom_revision_id' => $revision->id,
                    'planned_start_date' => today()->addDays($startOffset),
                    'planned_finish_date' => today()->addDays($startOffset + 2),
                    'planned_start_at' => today()->addDays($startOffset)->setTime(8, 0),
                    'planned_finish_at' => today()->addDays($startOffset + 2)->setTime(17, 0),
                    'required_delivery_date' => today()->addDays($startOffset + 3),
                    'required_delivery_at' => today()->addDays($startOffset + 3)->setTime(16, 30),
                    'responsible_user_id' => $user->id,
                    'notes' => 'UX demo: '.$note,
                    'created_by' => $user->id,
                    'updated_by' => $user->id,
                ],
            );

            ProductionOrderMaterial::query()->updateOrCreate(
                ['production_order_id' => $order->id, 'line_number' => 1],
                ['item_id' => $component->id, 'uom_id' => $uom->id, 'required_quantity' => $quantity * 2, 'source_bom_line_id' => $bomLine->id],
            );
        }
    }
}
