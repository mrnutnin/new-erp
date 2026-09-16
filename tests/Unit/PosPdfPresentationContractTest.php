<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosPdfPresentationContractTest extends TestCase
{
    public function test_sales_pdfs_use_the_shared_renderer_and_pdf_actions_open_in_a_new_tab(): void
    {
        $base = dirname(__DIR__, 2);
        $renderer = file_get_contents("{$base}/app/Modules/Platform/Services/DocumentPdfRenderer.php");
        $physicalSale = file_get_contents("{$base}/app/Modules/Pos/Views/pdf/physical-sale.blade.php");
        $physicalSaleIndex = file_get_contents("{$base}/app/Modules/Pos/Views/physical-sales/index.blade.php");
        $physicalSaleController = file_get_contents("{$base}/app/Modules/Pos/Controllers/PhysicalSaleController.php");

        self::assertStringContainsString('font-family:notosansthai', $renderer);
        self::assertStringContainsString("'<main class=\"document-render\">'", $renderer);
        self::assertStringContainsString('ใบเสร็จรับเงิน / ใบกำกับภาษีอย่างย่อ', $physicalSale);
        self::assertStringContainsString('ใบเสร็จรับเงิน / ใบกำกับภาษี', $physicalSale);
        self::assertStringContainsString('pdf-signatures', $physicalSale);
        self::assertStringContainsString('pdf-product', $physicalSale);
        self::assertStringContainsString('pdf-total-summary', $physicalSale);
        self::assertStringContainsString('มูลค่าสินค้าหรือบริการ', $physicalSale);
        self::assertStringContainsString('pdf-tax-invoice', $physicalSale);
        self::assertStringContainsString('pdf-footer-payment', $physicalSale);
        self::assertStringNotContainsString('บาท (THB)', $physicalSale);
        self::assertStringContainsString('วันที่เอกสาร / DOCUMENT DATE', $physicalSale);
        self::assertStringContainsString('อ้างอิง / REFERENCE', $physicalSale);
        self::assertStringContainsString('ภาษี / TAX', $physicalSale);
        self::assertStringContainsString('VAT EXCLUSIVE', $physicalSale);
        self::assertStringContainsString("rawurlencode(\$sale->document_number).'.pdf", $physicalSaleController);
        self::assertStringContainsString("'bx-printer', 'พิมพ์ PDF', true", $physicalSaleIndex);
        self::assertStringContainsString('target="_blank"', $physicalSaleIndex);
    }
}
