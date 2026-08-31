<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosPdfDetailHeaderContractTest extends TestCase
{
    public function test_sales_pdfs_render_the_detail_header_partial(): void
    {
        $base = dirname(__DIR__, 2);

        foreach (['physical-sale', 'sales-order', 'sales-quotation', 'sales-rfq', 'sales-intake'] as $template) {
            self::assertStringContainsString('Pos::pdf.partials.document-detail-header', file_get_contents("{$base}/app/Modules/Pos/Views/pdf/{$template}.blade.php"));
        }
    }
}
