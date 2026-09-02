<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PhysicalSaleTaxSummaryContractTest extends TestCase
{
    public function test_create_and_show_use_the_document_tax_values(): void
    {
        $form = file_get_contents(base_path('app/Modules/Pos/Views/physical-sales/form.blade.php'));
        $show = file_get_contents(base_path('app/Modules/Pos/Views/physical-sales/show.blade.php'));

        self::assertStringContainsString("Pos::partials.sales-tax-summary', ['document' => \$sourceOrder, 'sourceIntake' => \$sourceIntake]", $form);
        self::assertStringContainsString('number_format((float) $sale->tax_base, 2)', $show);
        self::assertStringContainsString('@php($withholdingMaximumBase = max(0, (float) $sale->tax_base))', $show);
        self::assertStringNotContainsString('number_format(0, 2)', $form);
    }
}
