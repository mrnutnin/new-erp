<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SalesPricePreviewContractTest extends TestCase
{
    public function test_direct_invoice_preview_uses_the_shared_price_list_resolver(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesDocumentController.php');
        $routes = file_get_contents($root.'/app/Modules/Pos/Routes/web.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/sales-documents/form.blade.php');

        $this->assertStringContainsString('function pricePreview', $controller);
        $this->assertStringContainsString('PricingResolver $resolver', $controller);
        $this->assertStringContainsString('applyPricingSnapshots', $controller);
        $this->assertStringContainsString("name('sales-documents.price-preview')", $routes);
        $this->assertStringContainsString("row.data('price-manual')", $view);
    }
}
