<?php

namespace Tests\Unit;

use Tests\TestCase;

final class ProductionAuditTrailContractTest extends TestCase
{
    public function test_production_mutations_keep_before_after_and_reversal_reason(): void
    {
        $orders = file_get_contents(base_path('app/Modules/Production/Services/ProductionOrderService.php'));
        $issues = file_get_contents(base_path('app/Modules/Wms/Services/IssueReturnService.php'));
        $receipt = file_get_contents(base_path('app/Modules/Wms/Services/ProductionFinishedReceiptReversalService.php'));
        $scrap = file_get_contents(base_path('app/Modules/Wms/Services/ProductionScrapReceiptService.php'));

        foreach (['production.order.updated', 'production.order.cancelled', 'production.order.deleted', 'cancellation_reason'] as $contract) {
            self::assertStringContainsString($contract, $orders);
        }
        foreach (['wms.issue.reversed', 'wms.issue_return.reversed', 'reversal_reason', '$before, $x->fresh()'] as $contract) {
            self::assertStringContainsString($contract, $issues);
        }
        foreach (['wms.production_finished_receipt.reversed', 'reversal_reason', '$before, $locked->fresh'] as $contract) {
            self::assertStringContainsString($contract, $receipt);
        }
        foreach (['wms.production_scrap_receipt.reversed', 'reversal_reason', '$before, $locked->fresh'] as $contract) {
            self::assertStringContainsString($contract, $scrap);
        }
    }
}
