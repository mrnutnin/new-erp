<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PromotionRfqSnapshotPropagationContractTest extends TestCase
{
    public function test_rfq_path_copies_frozen_promotion_values_without_repricing(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = file_get_contents($root.'/database/migrations/2026_08_30_190000_add_promotion_snapshots_to_sales_rfqs.php');
        $intake = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesIntakeController.php');
        $quotation = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesQuotationController.php');
        $order = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesOrderController.php');

        self::assertStringContainsString("'promotion_snapshot'", $migration);
        self::assertStringContainsString("'pricing_snapshot'", $migration);
        self::assertStringContainsString("'promotion_discount_amount'", $migration);
        self::assertStringContainsString("'promotion_snapshot' => \$intake->promotion_snapshot", $intake);
        self::assertStringContainsString("'pricing_snapshot' => \$line->pricing_snapshot", $intake);
        self::assertStringContainsString("'promotion_snapshot' => \$rfq->promotion_snapshot", $quotation);
        self::assertStringContainsString("'line_total' => \$line->line_total", $quotation);
        self::assertStringNotContainsString('SalesQuotationAmounts::calculate($rfq->lines', $quotation);
        self::assertStringContainsString("'promotion_snapshot' => \$rfq->promotion_snapshot", $order);
        self::assertStringContainsString("'pricing_snapshot' => \$line->pricing_snapshot", $order);
    }
}
