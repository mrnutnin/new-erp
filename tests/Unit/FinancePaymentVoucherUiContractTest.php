<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class FinancePaymentVoucherUiContractTest extends TestCase
{
    public function test_payment_voucher_keeps_workflow_actions_on_detail_only(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Finance/Controllers/PaymentVoucherController.php');
        $index = file_get_contents($root.'/app/Modules/Finance/Views/payment-vouchers/index.blade.php');
        $show = file_get_contents($root.'/app/Modules/Finance/Views/payment-vouchers/show.blade.php');

        self::assertStringNotContainsString("addColumn('submit_url'", $controller);
        self::assertStringNotContainsString('js-voucher-action', $index);
        self::assertStringContainsString('erpExcelButton(table)', $index);
        self::assertStringContainsString('กลับหน้ารายการ', $show);
        self::assertStringContainsString('ส่งอนุมัติ', $show);
        self::assertStringContainsString('สร้าง Settlement', $show);
        self::assertStringContainsString('ยกเลิกเอกสาร', $show);
    }
}
