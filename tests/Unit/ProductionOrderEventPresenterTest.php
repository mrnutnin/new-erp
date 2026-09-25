<?php

namespace Tests\Unit;

use App\Models\User;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderEvent;
use App\Modules\Production\Support\ProductionOrderEventPresenter;
use App\Modules\Wms\Models\Uom;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class ProductionOrderEventPresenterTest extends TestCase
{
    public function test_readiness_event_is_summarized_in_plain_thai_without_technical_payload(): void
    {
        $event = new ProductionOrderEvent([
            'event_type' => 'released',
            'payload' => ['ready' => true, 'rows' => [['status' => 'READY', 'required_quantity' => '100.00000000']]],
            'occurred_at' => '2026-09-25 08:41:00',
        ]);
        $event->setRelation('creator', new User(['name' => 'ผู้ดูแลระบบ']));

        $presented = ProductionOrderEventPresenter::present(new Collection([$event]), new ProductionOrder());

        self::assertSame('ปล่อยใบสั่งผลิตเข้าคิว', $presented[0]['title']);
        self::assertSame('ผู้ดูแลระบบ', $presented[0]['actor']);
        self::assertSame(['ตรวจวัตถุดิบ: พร้อม · 1 รายการ'], $presented[0]['summary']);
        self::assertStringNotContainsString('required_quantity', json_encode($presented[0]));
    }

    public function test_operation_event_names_the_work_step(): void
    {
        $event = new ProductionOrderEvent(['event_type' => 'operation_completed', 'payload' => ['name' => 'ประกอบชิ้นงาน', 'sequence' => 2]]);
        $event->setRelation('creator', new User(['name' => 'ช่างผลิต']));

        $presented = ProductionOrderEventPresenter::present(new Collection([$event]), new ProductionOrder());

        self::assertSame('จบขั้นตอนการผลิต', $presented[0]['title']);
        self::assertSame(['ขั้นตอน: ประกอบชิ้นงาน (ลำดับ 2)'], $presented[0]['summary']);
    }

    public function test_issue_resolution_shows_the_resolution_instead_of_an_internal_model_id(): void
    {
        $event = new ProductionOrderEvent([
            'event_type' => 'production_issue_resolved',
            'source_id' => '17',
            'payload' => ['description' => 'เครื่องจักรหยุด', 'resolution_method' => 'เปลี่ยนฟิวส์และทดสอบเดินเครื่องแล้ว'],
        ]);
        $event->setRelation('creator', new User(['name' => 'หัวหน้างาน']));

        $presented = ProductionOrderEventPresenter::present(new Collection([$event]), new ProductionOrder());

        self::assertSame('แก้ไขปัญหาการผลิตแล้ว', $presented[0]['title']);
        self::assertSame(['เครื่องจักรหยุด', 'วิธีแก้ไข: เปลี่ยนฟิวส์และทดสอบเดินเครื่องแล้ว'], $presented[0]['summary']);
        self::assertStringNotContainsString('#17', json_encode($presented[0]));
    }

    public function test_finished_receipt_event_shows_document_number_and_completed_quantity(): void
    {
        $event = new ProductionOrderEvent([
            'event_type' => 'finished_receipt_posted',
            'source_id' => '48',
            'source_type' => 'App\\Modules\\Wms\\Models\\InventoryAdjustmentDocument',
            'payload' => ['document_number' => 'FGRHQ2609000002', 'completed_quantity' => '5.00000000', 'completed' => true],
            'occurred_at' => '2026-09-25 08:55:00',
        ]);
        $event->setRelation('creator', new User(['name' => 'ผู้ดูแลระบบ']));
        $order = new ProductionOrder();
        $order->setRelation('uom', new Uom(['code' => 'PCS']));

        $presented = ProductionOrderEventPresenter::present(new Collection([$event]), $order);

        self::assertSame('รับสินค้าผลิตเสร็จเข้าคลัง', $presented[0]['title']);
        self::assertSame(['เลขที่เอกสาร FGRHQ2609000002', 'รับผลิตสะสม 5.00 PCS · ครบตามแผน'], $presented[0]['summary']);
        self::assertStringNotContainsString('InventoryAdjustmentDocument', json_encode($presented[0]));
        self::assertStringNotContainsString('#48', json_encode($presented[0]));
    }
}
