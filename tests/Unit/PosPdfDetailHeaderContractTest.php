<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosPdfDetailHeaderContractTest extends TestCase
{
    public function test_intake_and_rfq_pdfs_use_the_shared_clean_layout(): void
    {
        $base = dirname(__DIR__, 2);

        foreach (['sales-rfq' => 'ใบขอราคา', 'sales-intake' => 'ใบรับข้อมูล'] as $template => $title) {
            $view = file_get_contents("{$base}/app/Modules/Pos/Views/pdf/{$template}.blade.php");
            self::assertStringContainsString($title, $view);
            self::assertStringContainsString('<!-- invoice-closing -->', $view);
            self::assertStringContainsString('pdf-product', $view);
        }
    }

    public function test_quotation_pdf_uses_the_commercial_layout_and_document_number_filename(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/app/Modules/Pos/Views/pdf/sales-quotation.blade.php');
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesQuotationPdfController.php');

        self::assertStringContainsString('ใบเสนอราคา', $view);
        self::assertStringContainsString('QUOTATION', $view);
        self::assertStringContainsString('เอกสารทางการค้า', $view);
        self::assertStringNotContainsString('COMMERCIAL DOCUMENT', $view);
        self::assertStringNotContainsString('GRAND TOTAL', $view);
        self::assertStringContainsString('<!-- invoice-closing -->', $view);
        self::assertStringContainsString("rawurlencode(\$salesQuotation->document_number).'.pdf", $controller);
    }

    public function test_sales_order_pdf_uses_the_shared_clean_commercial_layout(): void
    {
        $root = dirname(__DIR__, 2);
        $view = file_get_contents($root.'/app/Modules/Pos/Views/pdf/sales-order.blade.php');

        self::assertStringContainsString('ใบสั่งขาย / SALES ORDER', $view);
        self::assertStringContainsString('<!-- invoice-closing -->', $view);
        self::assertStringContainsString('pdf-total-summary', $view);
        self::assertStringNotContainsString('เอกสารทางการค้า / COMMERCIAL DOCUMENT', $view);
    }

    public function test_remaining_pos_pdfs_use_the_shared_clean_layout(): void
    {
        $root = dirname(__DIR__, 2);

        foreach (['advance-deposit', 'receipt', 'sales-return'] as $template) {
            $view = file_get_contents("{$root}/app/Modules/Pos/Views/pdf/{$template}.blade.php");
            self::assertStringContainsString('pdf-header', $view);
            self::assertStringContainsString('pdf-product', $view);
            self::assertStringContainsString('<!-- invoice-closing -->', $view);
        }
    }
}
