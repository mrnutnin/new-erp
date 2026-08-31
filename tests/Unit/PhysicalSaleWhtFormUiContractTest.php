<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleWhtFormUiContractTest extends TestCase
{
    public function test_physical_sale_form_declares_optional_wht_fields_and_clears_them_when_not_selected(): void
    {
        $view = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Views/physical-sales/form.blade.php');

        self::assertStringContainsString('$whtTaxCodes ?? []', $view);
        self::assertStringContainsString('name="withholding_tax_code_id"', $view);
        self::assertStringContainsString('name="withholding_base"', $view);
        self::assertStringNotContainsString('name="withholding_amount"', $view);
        self::assertStringContainsString('readonly value="0.00"', $view);
        self::assertStringContainsString("detail.toggleClass('d-none',!enabled)", $view);
        self::assertStringContainsString("base.val('0.00')", $view);
        self::assertStringContainsString("base.on('input',()=>sync(false))", $view);
    }
}
