<?php

namespace Tests\Unit;

use Tests\TestCase;

final class SalesIntakeTaxSummaryUiContractTest extends TestCase
{
    public function test_vat_exclusive_summary_adds_vat_to_the_tax_base(): void
    {
        $view = file_get_contents(base_path('app/Modules/Pos/Views/sales-intakes/form.blade.php'));

        self::assertStringContainsString("mode==='VAT_EXCLUSIVE'?base*rate/100:gross-base", $view);
        self::assertStringContainsString('$(this).text(money(base+t))', $view);
    }
}
