<?php

namespace Tests\Unit;

use App\Modules\Platform\Services\DocumentPdfRenderer;
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
}
