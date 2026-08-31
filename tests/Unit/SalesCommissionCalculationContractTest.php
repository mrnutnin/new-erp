<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesCommissionCalculationContractTest extends TestCase
{
    public function test_commission_records_are_idempotent_and_use_immutable_posted_sources(): void
    {
        $root = dirname(__DIR__, 2);
        $service = file_get_contents($root.'/app/Modules/Pos/Services/CommissionCalculationService.php');
        $migration = file_get_contents($root.'/database/migrations/2026_08_30_201000_create_pos_sales_commission_records_table.php');
        $branchMigration = file_get_contents($root.'/database/migrations/2026_08_31_090000_add_branch_snapshot_to_pos_sales_commission_records.php');

        self::assertStringContainsString("whereIn('plan.basis', ['POSTED_SALE', 'GROSS_PROFIT'])", $service);
        self::assertStringContainsString("where('plan.basis', 'COLLECTED_RECEIPT')", $service);
        self::assertStringContainsString("->where('movements.status', 'POSTED')", $service);
        self::assertStringContainsString('firstOrCreate', $service);
        self::assertStringContainsString("'idempotency_key' => \$key", $service);
        self::assertStringContainsString("'status' => 'PENDING'", $service);
        self::assertStringContainsString("'status' => 'REVERSED'", $service);
        self::assertStringContainsString("orWhereNull('branch_id')", $service);
        self::assertStringContainsString('branchIdForSale', $service);
        self::assertStringContainsString("'PHYSICAL_SALE_CASH'", $service);
        self::assertStringContainsString('(float) $sale->total_amount', $service);
        self::assertStringContainsString("'settled_amount' => JournalBalance::decimal(\$sale->total_amount)", $service);
        self::assertStringContainsString("'tender_amount' => \$tenderAmount", $service);
        self::assertStringContainsString("'advance_applied_amount' => \$advanceAmount", $service);
        self::assertStringContainsString("['PHYSICAL_SALE_CASH', 'SETTLEMENT']", $service);
        self::assertStringContainsString("\$table->string('idempotency_key', 180)->unique()", $migration);
        self::assertStringContainsString("\$table->foreignId('physical_sale_id')", $migration);
        self::assertStringContainsString('COALESCE(sales.branch_id, warehouses.branch_id)', $branchMigration);
        self::assertStringContainsString('pos_commission_records_branch_scope_idx', $branchMigration);
    }

    public function test_post_and_reversal_lifecycle_hooks_use_the_same_engine(): void
    {
        $root = dirname(__DIR__, 2);
        $salePosting = file_get_contents($root.'/app/Modules/Pos/Services/PhysicalSalePostingService.php');
        $returnPosting = file_get_contents($root.'/app/Modules/Pos/Services/SalesReturnPostingService.php');
        $saleCancellation = file_get_contents($root.'/app/Modules/Pos/Services/PhysicalSaleCancellationService.php');
        $settlementPosting = file_get_contents($root.'/app/Modules/Finance/Services/SettlementPostingService.php');
        $settlementReversal = file_get_contents($root.'/app/Modules/Finance/Services/SettlementReversalService.php');
        $service = file_get_contents($root.'/app/Modules/Pos/Services/CommissionCalculationService.php');

        self::assertStringContainsString('calculatePostedSale($sale)', $salePosting);
        self::assertStringContainsString('reverseForPostedReturn($return->fresh()', $returnPosting);
        self::assertStringContainsString('reverseForPostedReturn($return->fresh()', $saleCancellation);
        self::assertStringContainsString('calculateCollectedReceipt($settlement)', $settlementPosting);
        self::assertStringContainsString('reverseForSettlement($settlement', $settlementReversal);
        self::assertStringContainsString('assertSettlementCanBeReversed($settlement)', $settlementReversal);
        self::assertStringContainsString("->where('status', 'PAID')", $service);
    }
}
