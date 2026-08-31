<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class PromotionSnapshotPropagationContractTest extends TestCase
{
    public function test_promotion_snapshots_are_persisted_and_copied_through_the_sales_flow(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = file_get_contents($root.'/database/migrations/2026_08_30_180000_add_promotion_snapshots_to_pos_sales_flow.php');
        $quotation = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesQuotationController.php');
        $order = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesOrderController.php');
        $sale = file_get_contents($root.'/app/Modules/Pos/Controllers/PhysicalSaleController.php');

        self::assertStringContainsString("'promotion_snapshot'", $migration);
        self::assertStringContainsString("'pricing_snapshot'", $migration);
        self::assertStringContainsString("'promotion_discount_amount'", $migration);

        self::assertStringContainsString("'promotion_snapshot' => \$intake->promotion_snapshot", $quotation);
        self::assertStringContainsString("'pricing_snapshot' => \$line->pricing_snapshot", $quotation);
        self::assertStringContainsString("'promotion_snapshot' => \$quotation->promotion_snapshot", $order);
        self::assertStringContainsString("'promotion_snapshot' => \$intake->promotion_snapshot", $order);
        self::assertStringContainsString("'pricing_snapshot' => \$line->pricing_snapshot", $order);
        self::assertStringContainsString("'promotion_snapshot' => \$source->promotion_snapshot", $sale);
        self::assertStringContainsString("'pricing_snapshot' => \$line->pricing_snapshot", $sale);
    }
}
