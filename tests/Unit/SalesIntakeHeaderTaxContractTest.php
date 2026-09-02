<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesIntakeHeaderTaxContractTest extends TestCase
{
    public function test_sales_intake_uses_one_header_tax_code_for_all_lines(): void
    {
        $root = dirname(__DIR__, 2);
        $form = file_get_contents($root.'/app/Modules/Pos/Views/sales-intakes/form.blade.php');
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesIntakeController.php');

        self::assertStringContainsString('name="tax_code_id"', $form);
        self::assertStringNotContainsString('name="lines[{{ $i }}][tax_code_id]"', $form);
        self::assertStringContainsString("find(\$d['tax_code_id'] ?? null)", $controller);
        self::assertStringNotContainsString("find(\$line['tax_code_id'] ?? null)", $controller);
    }
}
