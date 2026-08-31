<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PosPdfPresentationContractTest extends TestCase
{
    public function test_sales_pdfs_use_the_shared_modern_style_and_intake_has_a_print_route(): void
    {
        $base = dirname(__DIR__, 2);
        foreach (['physical-sale', 'sales-order', 'sales-quotation', 'sales-rfq', 'sales-intake'] as $template) {
            self::assertStringContainsString('Pos::pdf.partials.modern-style', file_get_contents("{$base}/app/Modules/Pos/Views/pdf/{$template}.blade.php"));
        }
        self::assertStringContainsString("Route::get('/sales-intakes/{salesIntake}/pdf'", file_get_contents("{$base}/app/Modules/Pos/Routes/web.php"));
    }
}
