<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SalesReturnFullHsFlowContractTest extends TestCase
{
    public function test_hs_return_supports_partial_cash_refund_and_an_open_forward_posting_flow(): void
    {
        $root = dirname(__DIR__, 2);
        $posting = file_get_contents($root.'/app/Modules/Pos/Services/SalesReturnPostingService.php');
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesReturnController.php');
        $cancellation = file_get_contents($root.'/app/Modules/Pos/Services/PhysicalSaleCancellationService.php');

        self::assertStringContainsString('postCashRefund', $posting);
        self::assertStringContainsString('refund_bank_account_id', $posting);
        self::assertStringNotContainsString('HS ต้องรับคืนเต็มจำนวนทุกบรรทัด', $controller);
        self::assertStringContainsString('วันที่ Post ใบรับคืนต้องไม่ก่อนวันที่ Post HS/IV ต้นทาง', $posting);
        self::assertStringContainsString('วันที่กลับรายการต้องไม่ก่อนวันที่ Post HS/IV ต้นทาง', $cancellation);
        self::assertStringContainsString("where('status', 'POSTED')", $cancellation);
        self::assertStringContainsString("where('status', 'DRAFT')", $cancellation);
        self::assertStringContainsString('pos.sales-return.voided-by-source-cancellation', $cancellation);
    }
}
