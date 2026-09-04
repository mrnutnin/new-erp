<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Support\PurchaseReturnWmsPostingContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PurchaseReturnWmsPostingContractTest extends TestCase
{
    public function test_full_return_has_atomic_wms_posting_plan(): void
    {
        $plan = PurchaseReturnWmsPostingContract::plan([
            'purchase_return_id' => 10, 'credit_note_id' => 20, 'credit_document_id' => 20,
            'return_status' => 'APPROVED', 'credit_status' => 'POSTED', 'credit_mode' => 'RETURN',
            'return_warehouse_id' => 1, 'credit_warehouse_id' => 1, 'return_supplier_id' => 2,
            'credit_supplier_id' => 2, 'full_line' => true,
        ]);

        self::assertSame('purchase-return:10:wms-post', $plan['idempotency_key']);
        self::assertTrue($plan['atomic']);
    }

    public function test_partial_return_is_blocked_until_wms_supports_partial_movement(): void
    {
        $this->expectException(ValidationException::class);
        PurchaseReturnWmsPostingContract::plan([
            'purchase_return_id' => 10, 'credit_note_id' => 20, 'credit_document_id' => 20,
            'return_status' => 'APPROVED', 'credit_status' => 'POSTED', 'credit_mode' => 'RETURN',
            'return_warehouse_id' => 1, 'credit_warehouse_id' => 1, 'return_supplier_id' => 2,
            'credit_supplier_id' => 2, 'full_line' => false,
        ]);
    }
}
