<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleVatPostingContractTest extends TestCase
{
    public function test_physical_sale_freezes_calculated_vat_and_posts_deferred_output_vat_for_hs_and_iv(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/PhysicalSaleController.php');
        $plan = file_get_contents($root.'/app/Modules/Pos/Support/PhysicalSaleRevenuePostingPlan.php');
        $migration = file_get_contents($root.'/database/migrations/2026_08_29_170000_add_vat_snapshots_to_physical_sales.php');

        self::assertStringContainsString('SalesDocumentCalculator::calculate($draftLines', $controller);
        self::assertStringContainsString("'tax_base' => \$calculation['tax_base']", $controller);
        self::assertStringContainsString("'tax_code_id' => \$calculated['tax_code_id']", $controller);
        self::assertStringContainsString('PhysicalSaleWithholdingSnapshot::build($tax, $request->input(\'withholding_base\', \'0.00\'), $draft->tax_base)', $controller);
        self::assertStringContainsString("resolve('DEFERRED_OUTPUT_VAT')", $plan);
        self::assertStringContainsString("'tax_code_id' => \$taxCodeIds->first()", $plan);
        self::assertStringContainsString('private function invoiceJournal', $plan);
        self::assertStringContainsString("decimal('tax_base'", $migration);
        self::assertStringContainsString("foreignId('tax_code_id')", $migration);
    }
}
