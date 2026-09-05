<?php

namespace Tests\Unit;

use App\Modules\Platform\Services\DocumentPdfRenderer;
use App\Modules\Platform\Services\DocumentTemplateRenderService;
use InvalidArgumentException;
use Tests\TestCase;

class DocumentPdfRendererTest extends TestCase
{
    public function test_it_renders_the_shared_a4_profile(): void
    {
        $pdf = app(DocumentPdfRenderer::class)->renderView('pdf.document', [
            'document' => ['title' => 'Purchase Order', 'number' => 'PO-TEST-001', 'date' => '22/08/2026', 'status' => 'ร่าง'],
            'lines' => [['line_number' => 1, 'description' => 'สินค้า', 'uom' => 'PCS', 'quantity' => '2', 'unit_price' => '10.00', 'amount' => '20.00']],
            'totals' => ['grand_total' => '20.00'],
        ]);

        $this->assertStringStartsWith('%PDF-', $pdf);
    }

    public function test_unknown_profile_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(DocumentPdfRenderer::class)->render('<p>x</p>', 'unknown');
    }

    public function test_document_template_html_and_pdf_keep_section_order_and_hide_duplicate_metadata(): void
    {
        $html = app(DocumentTemplateRenderService::class)->render('PURCHASE_ORDER', ['sections' => [
            ['type' => 'image', 'field' => 'company.logo', 'align' => 'center', 'size' => 80, 'visible' => true],
            ['type' => 'field', 'field' => 'company.name', 'align' => 'center', 'visible' => true],
            ['type' => 'field', 'field' => 'document.number', 'visible' => true],
        ]], [
            'company' => ['logo' => null, 'name' => 'บริษัททดสอบ', 'address' => 'ที่อยู่ทดสอบ', 'tax_id' => '123'],
            'party' => ['name' => 'Supplier', 'address' => 'Supplier address'],
            'document' => ['title' => 'ใบสั่งซื้อ', 'number' => 'PO-TEST-001', 'date' => '04/09/2026', 'status' => 'DRAFT'],
            'lines' => [], 'totals' => [], 'signatures' => [],
        ]);

        self::assertLessThan(strpos($html, 'Supplier / Customer'), strpos($html, 'บริษัททดสอบ'));
        self::assertSame(1, substr_count($html, 'PO-TEST-001'));
        self::assertStringStartsWith('%PDF-', app(DocumentPdfRenderer::class)->render($html));
    }
}
