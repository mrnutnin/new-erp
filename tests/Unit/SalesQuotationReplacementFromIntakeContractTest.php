<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesQuotationReplacementFromIntakeContractTest extends TestCase
{
    public function test_cancelled_intake_quotation_can_be_replaced(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = file_get_contents($root.'/database/migrations/2026_09_02_113000_allow_replacement_sales_quotations_from_intakes.php');
        $model = file_get_contents($root.'/app/Modules/Pos/Models/SalesIntake.php');

        self::assertStringContainsString("dropUnique('sales_quotations_intake_unique')", $migration);
        self::assertStringContainsString("index('source_sales_intake_id', 'sales_quotations_intake_idx')", $migration);
        self::assertStringContainsString('latestOfMany()', $model);
    }

    public function test_intake_conversion_keeps_a_non_javascript_redirect_fallback(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesQuotationController.php');

        self::assertStringContainsString('public function fromIntake(Request $request, SalesIntake $salesIntake, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse|RedirectResponse', $controller);
        self::assertStringContainsString("return redirect()->route('pos.sales-quotations.show', \$quotation)->with('success', 'สร้างใบเสนอราคาจากใบรับข้อมูลแล้ว');", $controller);
    }
}
