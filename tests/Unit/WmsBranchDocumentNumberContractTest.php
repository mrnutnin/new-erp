<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Controllers\PurchaseReceiptController;
use App\Modules\Purchasing\Controllers\PurchaseRequisitionController;
use App\Modules\Wms\Controllers\StockCountController;
use App\Modules\Wms\Controllers\TransferController;
use App\Modules\Purchasing\Services\GoodsReceiptService;
use PHPUnit\Framework\TestCase;

final class WmsBranchDocumentNumberContractTest extends TestCase
{
    public function test_legacy_wms_headers_use_branch_aware_document_sequences(): void
    {
        foreach ([PurchaseRequisitionController::class, TransferController::class, StockCountController::class, GoodsReceiptService::class] as $class) {
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());

            self::assertStringContainsString('issueAvailableForBranch', $source);
            self::assertStringContainsString('recordIssued', $source);
        }
    }

    public function test_manual_number_inputs_are_not_accepted_for_goods_receipt_or_transfer(): void
    {
        $receipt = file_get_contents((new \ReflectionClass(PurchaseReceiptController::class))->getFileName());
        $transfer = file_get_contents((new \ReflectionClass(TransferController::class))->getFileName());

        self::assertStringNotContainsString("'receipt_number' => ['required'", $receipt);
        self::assertStringNotContainsString("'document_number' => ['required'", $transfer);
    }
}
