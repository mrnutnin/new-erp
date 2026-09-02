<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesIntakeEditRedirectAndTaxDisplayContractTest extends TestCase
{
    public function test_edit_redirects_to_detail_and_tax_display_uses_intake_setting(): void
    {
        $root = dirname(__DIR__, 2);
        $form = file_get_contents($root.'/app/Modules/Pos/Views/sales-intakes/form.blade.php');
        $header = file_get_contents($root.'/app/Modules/Pos/Views/partials/sales-document-header.blade.php');

        self::assertStringContainsString("window.erpAjaxForm({form:'[data-sales-intake-form]',redirect:true})", $form);
        self::assertStringContainsString('$intake?->prices_include_vat', $header);
    }
}
