<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PurchasingPurchaseRequisitionPdfContractTest extends TestCase
{
    public function test_purchase_requisition_uses_the_clean_internal_a4_pdf_contract(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Purchasing/Routes/web.php'));
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseDocumentPdfController.php'));
        $model = file_get_contents(base_path('app/Modules/Purchasing/Models/PurchaseRequisition.php'));
        $pdf = file_get_contents(base_path('app/Modules/Purchasing/Views/pdf/purchase-requisition.blade.php'));

        self::assertStringContainsString("Route::get('/purchase-requisitions/{purchaseRequisition}/pdf'", $routes);
        self::assertStringContainsString('permission:purchasing.purchase-requisitions.print', $routes);
        self::assertStringContainsString("renderView('Purchasing::pdf.purchase-requisition'", $controller);
        foreach (['warehouse.branch', 'purchaseOrder', 'createdBy', 'submittedBy', 'approvedBy'] as $relation) {
            self::assertStringContainsString($relation, $controller);
        }
        self::assertStringContainsString('rawurlencode($document->document_number)', $controller);
        self::assertStringContainsString('function submittedBy()', $model);
        self::assertStringContainsString('function approvedBy()', $model);

        foreach (['ใบขอซื้อ', 'เอกสารภายใน', 'วัตถุประสงค์การขอซื้อ', 'คลังที่ขอซื้อ', 'pdf-product', 'pdf-footer', 'pdf-signatures', 'ผู้ขอซื้อ', 'ผู้ส่งอนุมัติ', 'ผู้อนุมัติ', 'ร่าง — ยังไม่ได้ส่งอนุมัติ', 'ตีกลับ — กรุณาแก้ไขก่อนส่งใหม่'] as $expected) {
            self::assertStringContainsString($expected, $pdf);
        }
    }

    public function test_purchase_requisition_pdf_actions_open_in_a_new_tab(): void
    {
        $index = file_get_contents(base_path('app/Modules/Purchasing/Views/purchase-requisitions/index.blade.php'));
        $show = file_get_contents(base_path('app/Modules/Purchasing/Views/purchase-requisitions/show.blade.php'));

        self::assertStringContainsString('target="_blank"', $index);
        self::assertStringContainsString('target="_blank"', $show);
    }
}
