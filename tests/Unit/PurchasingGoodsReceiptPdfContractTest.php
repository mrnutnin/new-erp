<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PurchasingGoodsReceiptPdfContractTest extends TestCase
{
    public function test_goods_receipt_pdf_uses_standard_a4_layout_and_is_permissioned(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Purchasing/Routes/web.php'));
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseDocumentPdfController.php'));
        $pdf = file_get_contents(base_path('app/Modules/Purchasing/Views/pdf/goods-receipt.blade.php'));

        self::assertStringContainsString("Route::get('/purchase-receipts/{purchaseReceipt}/pdf'", $routes);
        self::assertStringContainsString('permission:purchasing.purchase-receipts.print', $routes);
        self::assertStringContainsString("renderView('Purchasing::pdf.goods-receipt'", $controller);
        self::assertStringContainsString('warehouse.branch', $controller);
        self::assertStringContainsString('purchaseOrder', $controller);
        self::assertStringContainsString('lines.purchaseUom', $controller);
        self::assertStringContainsString('lines.stockUom', $controller);
        self::assertStringContainsString('createdBy', $controller);
        self::assertStringContainsString('approvedBy', $controller);
        self::assertStringContainsString('rawurlencode($document->receipt_number)', $controller);
        self::assertStringContainsString("'Content-Disposition' => 'inline; filename=\"'", $controller);

        foreach (['ใบรับสินค้า / GOODS RECEIPT', 'pdf-product', 'pdf-total-summary', 'pdf-signatures', 'ผู้ขาย / Supplier', 'อ้างอิง PO', 'คลังรับสินค้า', 'หน่วยซื้อ', 'หน่วยสต็อก', 'ร่าง — ยังไม่กระทบสินค้าคงคลัง'] as $expected) {
            self::assertStringContainsString($expected, $pdf);
        }
    }

    public function test_goods_receipt_pdf_actions_open_in_a_new_tab(): void
    {
        $index = file_get_contents(base_path('app/Modules/Purchasing/Views/purchase-receipts/index.blade.php'));
        $show = file_get_contents(base_path('app/Modules/Purchasing/Views/purchase-receipts/show.blade.php'));

        self::assertStringContainsString('target="_blank"', $index);
        self::assertStringContainsString('target="_blank"', $show);
    }
}
