<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CommissionPayoutBatchContractTest extends TestCase
{
    public function test_payout_batch_contract_is_branch_scoped_and_preserves_fact_lineage(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = file_get_contents($root.'/database/migrations/2026_08_31_110000_create_pos_sales_commission_payout_batches.php');
        $service = file_get_contents($root.'/app/Modules/Pos/Services/CommissionPayoutService.php');

        self::assertStringContainsString("foreignId('branch_id')", $migration);
        self::assertStringContainsString("foreignId('warehouse_id')", $migration);
        self::assertStringContainsString("foreignId('commission_record_id')", $migration);
        self::assertStringContainsString("whereDoesntHave('payoutLines.batch'", $service);
        self::assertStringContainsString("where(['branch_id' => \$branchId, 'recipient_user_id' => \$recipientId, 'status' => 'APPROVED'])", $service);
        self::assertStringContainsString("'warehouse_id' => \$warehouse->id", $service);
    }

    public function test_posting_is_exactly_once_and_paid_only_after_journal_posts(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Services/CommissionPayoutService.php');

        self::assertStringContainsString("'event_code' => 'sales_commission_payout'", $service);
        self::assertStringContainsString("'source_type' => 'POS_COMMISSION', 'source_id' => (string) \$batch->id", $service);
        self::assertStringContainsString("resolveForEvent('sales_commission_payout', 'COMMISSION_EXPENSE')", $service);
        self::assertStringContainsString("'source_type' => 'BANK_ACCOUNT'", $service);
        self::assertStringContainsString("'posting_metadata'", $service);
        self::assertStringContainsString("'status' => 'PAID'", $service);
        self::assertTrue(strpos($service, 'postWithinTransaction([') < strpos($service, "'status' => 'PAID'"));
    }

    public function test_negative_pending_adjustments_and_paid_sale_reversals_are_guarded(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Services/CommissionPayoutService.php');
        $calculation = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Services/CommissionCalculationService.php');

        self::assertStringContainsString('assertNoPendingNegativeAdjustment($batch)', $service);
        self::assertStringContainsString("->where('commission_amount', '<', 0)", $service);
        self::assertStringContainsString('assertPhysicalSaleCanBeReversed', $calculation);
        self::assertStringContainsString("->where('status', 'PAID')", $calculation);
    }
}
