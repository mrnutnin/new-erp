<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesQuotationDirectIntakeContractTest extends TestCase
{
    public function test_direct_intake_quotation_does_not_require_an_rfq_source(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = file_get_contents($root.'/database/migrations/2026_09_02_110000_allow_direct_sales_intake_quotations.php');
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesQuotationController.php');

        self::assertStringContainsString("foreignId('sales_rfq_id')->nullable()->change()", $migration);
        self::assertStringContainsString("foreignId('source_rfq_line_id')->nullable()->change()", $migration);
        self::assertStringContainsString("'source_sales_intake_id' => \$intake->id", $controller);
        self::assertStringContainsString("'description' => \$this->lineDescription(\$line)", $controller);
        self::assertStringContainsString("\$intake->quotation->status !== 'CANCELLED'", $controller);
    }
}
