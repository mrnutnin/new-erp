<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CommissionPaymentBatchContractTest extends TestCase
{
    public function test_pos_batch_is_branch_scoped_and_prevents_duplicate_submission_of_a_record(): void
    {
        $root = dirname(__DIR__, 2);
        $migration = file_get_contents($root.'/database/migrations/2026_08_31_123000_create_pos_sales_commission_payment_batches.php');
        $service = file_get_contents($root.'/app/Modules/Pos/Services/CommissionPaymentBatchService.php');

        self::assertStringContainsString('pos_sales_commission_payment_batches', $migration);
        self::assertStringContainsString("where('branch_id', \$branchId)", $service);
        self::assertStringContainsString("whereDoesntHave('paymentBatchLines.batch'", $service);
        self::assertStringContainsString('cancelDraft', $service);
        self::assertStringContainsString('cancelForFinance', $service);
        self::assertStringContainsString("['SUBMITTED', 'VERIFIED']", $service);
    }

    public function test_pos_creates_combined_batch_and_finance_receives_submitted_batch_only(): void
    {
        $root = dirname(__DIR__, 2);
        $create = file_get_contents($root.'/app/Modules/Pos/Views/sales-commission-payment-batches/create.blade.php');
        $finance = file_get_contents($root.'/app/Modules/Finance/Controllers/CommissionPayoutController.php');

        self::assertStringContainsString('selection_mode', $create);
        self::assertStringContainsString('recipient_ids[]', $create);
        self::assertStringContainsString("whereIn('status', ['SUBMITTED', 'VERIFIED'])", $finance);
        self::assertStringContainsString('recipientTotals', $finance);

        $posController = file_get_contents($root.'/app/Modules/Pos/Controllers/CommissionPaymentBatchController.php');
        $posShow = file_get_contents($root.'/app/Modules/Pos/Views/sales-commission-payment-batches/show.blade.php');
        self::assertStringContainsString('recipientDetails', $posController);
        self::assertStringContainsString('lines.commissionRecord.physicalSale', $posController);
        self::assertStringContainsString('ดูรายละเอียด', $posShow);
        self::assertStringContainsString('เอกสารอ้างอิง', $posShow);
        self::assertStringContainsString('ประวัติเอกสาร', $posShow);
        self::assertStringContainsString("where('subject_type', \$batch->getMorphClass())", $posController);

        $service = file_get_contents($root.'/app/Modules/Pos/Services/CommissionPaymentBatchService.php');
        self::assertStringContainsString("['DRAFT', 'SUBMITTED']", $service);
        self::assertStringContainsString("'status' => 'CANCELLED'", $service);
        self::assertStringContainsString("'cancellation_reason' => \$reason", $service);
        self::assertStringContainsString("'cancellation_source' => \$source", $service);
        self::assertStringNotContainsString("'status' => 'PENDING'", $service);
    }
}
