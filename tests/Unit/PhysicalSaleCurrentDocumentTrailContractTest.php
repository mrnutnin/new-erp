<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleCurrentDocumentTrailContractTest extends TestCase
{
    public function test_physical_sale_show_replaces_the_source_trail_sale_with_the_current_document(): void
    {
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Controllers/PhysicalSaleController.php');

        self::assertStringContainsString('SalesDocumentTrail::for($source)', $controller);
        self::assertStringContainsString('$flowDocuments[strtolower($sale->document_type)] = $sale;', $controller);
    }
}
