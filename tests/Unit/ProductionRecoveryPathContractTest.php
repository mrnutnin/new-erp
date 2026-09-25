<?php

namespace Tests\Unit;

use Tests\TestCase;

final class ProductionRecoveryPathContractTest extends TestCase
{
    public function test_completed_work_order_recovery_is_stepwise_and_guards_stock_use(): void
    {
        $controller = file_get_contents(base_path('app/Modules/Production/Controllers/OrderController.php'));
        $routes = file_get_contents(base_path('app/Modules/Production/Routes/web.php'));
        $view = file_get_contents(base_path('app/Modules/Production/Views/orders/show.blade.php'));
        $reversal = file_get_contents(base_path('app/Modules/Wms/Services/ProductionFinishedReceiptReversalService.php'));

        self::assertStringContainsString("'materials_returnable' => ['required', 'accepted']", $controller);
        self::assertStringContainsString('public function reverseFinishedReceipt(', $controller);
        self::assertStringContainsString("'reason' => ['required', 'string', 'min:10', 'max:500']", $controller);
        self::assertStringContainsString("name('orders.finished-receipts.reverse')", $routes);
        self::assertStringContainsString('data-needs-material-confirm="1"', $view);
        self::assertStringContainsString('assertNoDownstreamFinishedGoodsUse($movements)', $reversal);
        self::assertStringContainsString('$this->assertFinishedGoodsAvailable($movements)', $reversal);
        self::assertStringContainsString("where('direction', 'OUT')->where('status', 'POSTED')", $reversal);
    }

    public function test_each_production_recovery_path_reverses_central_stock_and_syncs_the_wo(): void
    {
        $issues = file_get_contents(base_path('app/Modules/Wms/Services/IssueReturnService.php'));
        $receipt = file_get_contents(base_path('app/Modules/Wms/Services/ProductionFinishedReceiptReversalService.php'));
        $scrap = file_get_contents(base_path('app/Modules/Wms/Services/ProductionScrapReceiptService.php'));

        self::assertStringContainsString('public function reverseIssue(', $issues);
        self::assertStringContainsString('syncProductionOrderAfterMaterialIssueReversed', $issues);
        self::assertStringContainsString('material_issue_reversed', $issues);
        self::assertStringContainsString("'status' => 'RELEASED'", $issues);
        self::assertStringContainsString('public function reverseReturn(', $issues);
        self::assertStringContainsString('material_return_reversed', $issues);
        self::assertStringContainsString('reverseWithinTransaction($sourceMovement', $issues);

        self::assertStringContainsString('syncProductionOrderAfterReversal', $receipt);
        self::assertStringContainsString('finished_receipt_reversed', $receipt);
        self::assertStringContainsString('$this->reservations->release($reservation)', $receipt);

        self::assertStringContainsString('public function reverse(', $scrap);
        self::assertStringContainsString("'scrap_receipt_reversed'", $scrap);
        self::assertStringContainsString('reverseWithinTransaction($movements[$index]', $scrap);
    }
}
