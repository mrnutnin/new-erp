<?php

namespace Tests\Unit;

use Tests\TestCase;

final class ProductionRecoveryPathContractTest extends TestCase
{
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
