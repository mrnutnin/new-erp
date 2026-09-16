<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PurchasingPurchaseDocumentPdfContractTest extends TestCase
{
    public function test_purchase_invoice_and_credit_note_use_the_internal_a4_pdf_contract(): void
    {
        $routes = file_get_contents(base_path('app/Modules/Purchasing/Routes/web.php'));
        $controller = file_get_contents(base_path('app/Modules/Purchasing/Controllers/PurchaseDocumentPdfController.php'));
        $model = file_get_contents(base_path('app/Modules/Purchasing/Models/PurchaseDocument.php'));
        $pdf = file_get_contents(base_path('app/Modules/Purchasing/Views/pdf/purchase-document.blade.php'));

        self::assertStringContainsString("Route::get('/purchase-documents/{purchaseDocument}/pdf'", $routes);
        self::assertStringContainsString('permission:purchasing.purchase-documents.print', $routes);
        self::assertStringContainsString("renderView('Purchasing::pdf.purchase-document'", $controller);
        foreach (['warehouse.branch', 'originalDocument', 'lines.purchaseOrderLine.purchaseOrder', 'lines.receiptAllocations.goodsReceiptLine.goodsReceipt', 'createdBy', 'approvedBy', 'postedBy'] as $relation) {
            self::assertStringContainsString($relation, $controller);
        }
        self::assertStringContainsString('rawurlencode($document->document_number)', $controller);
        self::assertStringContainsString("'Content-Disposition' => 'inline; filename=\"'", $controller);
        self::assertStringContainsString('function approvedBy()', $model);
        self::assertStringContainsString('function postedBy()', $model);

        foreach (['ใบตั้งหนี้ซื้อ', 'ใบลดหนี้ซื้อ', 'สำเนาภายใน / INTERNAL COPY', 'ไม่ใช่ใบกำกับภาษีที่ออกโดยผู้ขาย', 'อ้างอิงใบตั้งหนี้เดิม', 'อ้างอิง PO', 'อ้างอิงใบรับสินค้า', 'pdf-product', 'pdf-total-summary', 'pdf-signatures', 'มูลค่าเอกสารเดิม', 'คงเหลือหลังลดหนี้', 'ร่าง — ยังไม่ผ่านการอนุมัติ'] as $expected) {
            self::assertStringContainsString($expected, $pdf);
        }
        self::assertSame(2, substr_count($pdf, 'pdf-tax-invoice pdf-readable'));
        self::assertStringContainsString('pdf-signatures pdf-signatures-three', $pdf);
        self::assertSame(2, substr_count($pdf, 'class="invoice-sign-gap"'));
        self::assertStringContainsString('.pdf-tax-invoice.pdf-readable .pdf-signatures td { font-size:10pt; }', file_get_contents(base_path('app/Modules/Platform/Services/DocumentPdfRenderer.php')));
        self::assertStringContainsString('.pdf-signatures.pdf-signatures-three td { width:31.333%; }', file_get_contents(base_path('app/Modules/Platform/Services/DocumentPdfRenderer.php')));
        self::assertStringNotContainsString('number_format((float)', $pdf);
    }

    public function test_purchase_document_pdf_actions_open_in_a_new_tab(): void
    {
        $index = file_get_contents(base_path('app/Modules/Purchasing/Views/purchase-documents/index.blade.php'));
        $show = file_get_contents(base_path('app/Modules/Purchasing/Views/purchase-documents/show.blade.php'));

        self::assertStringContainsString('target="_blank"', $index);
        self::assertStringContainsString('target="_blank"', $show);
    }
}
