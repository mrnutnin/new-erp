<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DownstreamSalesTaxDisplayContractTest extends TestCase
{
    public function test_downstream_documents_use_the_intake_tax_profile(): void
    {
        $root = dirname(__DIR__, 2);
        $header = file_get_contents($root.'/app/Modules/Pos/Views/partials/sales-document-header.blade.php');
        $pdfHeader = file_get_contents($root.'/app/Modules/Pos/Views/pdf/partials/document-detail-header.blade.php');

        foreach ([$header, $pdfHeader] as $template) {
            self::assertStringContainsString('$intake?->lines ??', $template);
        }
        self::assertStringContainsString('$intake?->prices_include_vat', $header);
        self::assertStringContainsString("? 'รวมภาษี' : 'ภาษีนอก'", $header);
        foreach (['sales-quotations/show', 'sales-orders/show', 'physical-sales/show'] as $template) {
            self::assertStringContainsString("Pos::partials.sales-document-header", file_get_contents("{$root}/app/Modules/Pos/Views/{$template}.blade.php"));
        }
    }
}
