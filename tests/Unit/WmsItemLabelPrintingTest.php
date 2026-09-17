<?php

namespace Tests\Unit;

use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\ItemCategory;
use App\Modules\Wms\Models\Uom;
use Illuminate\Support\Collection;
use Tests\TestCase;

class WmsItemLabelPrintingTest extends TestCase
{
    public function test_bulk_label_routes_are_permission_protected_and_available_from_items(): void
    {
        $root = dirname(__DIR__, 2);
        $routes = file_get_contents($root.'/app/Modules/Wms/Routes/web.php');
        $index = file_get_contents($root.'/app/Modules/Wms/Views/items/index.blade.php');
        $rbac = file_get_contents($root.'/database/seeders/RbacSeeder.php');
        $installer = file_get_contents($root.'/app/Modules/Installer/Services/SystemDefaultOrchestrator.php');

        self::assertStringContainsString("Route::get('/items/labels'", $routes);
        self::assertStringContainsString("Route::get('/items/labels/print'", $routes);
        self::assertSame(2, substr_count($routes, "permission:wms.items.print"));
        self::assertStringContainsString('js-item-select', $index);
        self::assertStringContainsString('selectedItems', $index);
        self::assertStringContainsString("'wms.items.print'", $rbac);
        self::assertStringContainsString("'core.rbac' => '2.1'", $installer);
    }

    public function test_shared_renderer_outputs_a_qr_and_barcode_sticker_pdf(): void
    {
        $item = (new Item(['code' => 'ITEM-001', 'name' => 'สินค้าทดสอบ', 'base_uom' => 'PCS']))->forceFill(['id' => 1]);
        $item->setRelation('category', new ItemCategory(['code' => 'GOODS', 'name' => 'สินค้าทั่วไป']));
        $item->setRelation('baseUom', (new Uom(['code' => 'PCS', 'name' => 'ชิ้น']))->forceFill(['id' => 1]));

        $pdf = app(DocumentPdfRenderer::class)->renderView('Wms::pdf.item-labels', [
            'items' => new Collection([$item]),
            'companyName' => 'บริษัททดสอบ',
            'symbol' => 'BOTH',
            'copies' => 1,
            'large' => false,
            'labelSize' => '50x30',
            'sheet' => false,
            'columns' => 1,
            'rows' => 1,
            'cellHeight' => null,
        ], 'label_50x30');

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertArrayHasKey('label_40x30', config('erp.pdf.profiles'));
        self::assertArrayHasKey('label_100x50', config('erp.pdf.profiles'));
        self::assertArrayHasKey('label_a4', config('erp.pdf.profiles'));
    }

    public function test_a4_sheet_renders_long_item_names_and_skus_in_one_pdf(): void
    {
        $item = (new Item([
            'code' => 'SKU-VERY-LONG-ABCDEFGHIJKLMNOPQRSTUVWXYZ-001',
            'name' => 'ชื่อสินค้าที่ยาวมากสำหรับทดสอบการตัดบรรทัดบนสติกเกอร์สินค้า',
            'base_uom' => 'PCS',
        ]))->forceFill(['id' => 1]);
        $item->setRelation('category', new ItemCategory(['name' => 'หมวดสินค้าชื่อยาวสำหรับทดสอบ']));
        $item->setRelation('baseUom', (new Uom(['code' => 'PCS', 'name' => 'ชิ้น']))->forceFill(['id' => 1]));

        $pdf = app(DocumentPdfRenderer::class)->renderView('Wms::pdf.item-labels', [
            'items' => new Collection([$item]), 'companyName' => 'บริษัททดสอบ', 'symbol' => 'QR', 'copies' => 3,
            'large' => true, 'labelSize' => 'a4_3x7', 'sheet' => true, 'columns' => 3, 'rows' => 7, 'cellHeight' => 40.4,
        ], 'label_a4');

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertStringContainsString('pdf-label-sheet', file_get_contents(base_path('app/Modules/Wms/Views/pdf/item-labels.blade.php')));
        self::assertStringContainsString('sticker-code-very-long', file_get_contents(base_path('app/Modules/Platform/Services/DocumentPdfRenderer.php')));
    }
}
