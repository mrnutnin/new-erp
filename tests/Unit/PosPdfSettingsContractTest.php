<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosPdfSettingsContractTest extends TestCase
{
    public function test_pos_pdf_renderers_only_use_registered_decimal_setting(): void
    {
        $root = dirname(__DIR__, 2);
        $sources = file_get_contents($root.'/app/Modules/Pos/Controllers/PhysicalSaleController.php')
            .file_get_contents($root.'/app/Modules/Pos/Controllers/SalesReturnController.php');

        self::assertStringNotContainsString("value('quantity_decimal_places')", $sources);
        self::assertStringContainsString("value('tax_decimal_places')", $sources);
    }
}
