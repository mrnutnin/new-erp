<?php

namespace Tests\Unit;

use Tests\TestCase;

final class SavePurchaseReturnRequestTest extends TestCase
{
    public function test_return_request_has_source_quantity_and_eligibility_guards(): void
    {
        $request = file_get_contents(base_path('app/Modules/Purchasing/Requests/SavePurchaseReturnRequest.php'));

        foreach (['goods_receipt_id', 'purchase_document_id', 'return_date', 'reason', 'lines.*.goods_receipt_line_id', 'lines.*.purchase_quantity', 'PurchaseReturnEligibilityService', 'assertLineQuantityAllowed'] as $contract) {
            self::assertStringContainsString($contract, $request);
        }
        self::assertStringContainsString("'distinct'", $request);
        self::assertStringContainsString('รายการต้องอยู่ใน Goods Receipt ที่เลือก', $request);
    }
}
