<?php

namespace Tests\Unit;

use Tests\TestCase;

final class ProductionMvpWorkflowContractTest extends TestCase
{
    public function test_demand_to_finished_goods_reservation_pipeline_is_wired_through_central_services(): void
    {
        $orders = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $service = file_get_contents(base_path('app/Modules/Production/Services/ProductionOrderService.php'));
        $issues = file_get_contents(base_path('app/Modules/Wms/Services/IssueReturnService.php'));
        $receipt = file_get_contents(base_path('app/Modules/Wms/Services/ManualProductionReceiptPostingService.php'));
        $pos = file_get_contents(base_path('app/Modules/Pos/Services/PhysicalSalePostingService.php'));

        foreach (['storeFromDemand(', 'createMaterialIssue(', 'createFinishedReceipt(', 'postMaterialIssue(', 'postFinishedReceipt('] as $contract) {
            self::assertStringContainsString($contract, $orders);
        }
        self::assertStringContainsString('createFromSalesOrderLine(', $service);
        self::assertStringContainsString("'event_type' => 'material_issue_created'", $service);
        self::assertStringContainsString('StockReservationService::class)->consume', $issues);
        self::assertStringContainsString("'event_type' => 'material_issue_posted'", $issues);
        self::assertStringContainsString('syncProductionOrderCompletion', $receipt);
        self::assertStringContainsString("'event_type' => 'finished_goods_reserved'", $receipt);
        self::assertStringContainsString('StockReservation::SOURCE_SALES_ORDER_LINE', $receipt);
        self::assertStringContainsString('$this->reservations->consume($reservation', $pos);
    }
}
