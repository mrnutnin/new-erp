<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\CreditPurchaseInventoryReversalContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CreditPurchaseInventoryReversalContractTest extends TestCase
{
    public function test_full_gr_line_plan_is_immutable_and_idempotent(): void
    {
        $plan = CreditPurchaseInventoryReversalContract::plan($this->source(), ['reason' => 'คืนสินค้าตามใบลดหนี้', 'date' => '2026-08-23']);

        $this->assertSame('reversal:credit-purchase:20:movement:40:revision:1', $plan['idempotency_key']);
        $this->assertSame('credit_journal_line', $plan['journal_linkage']);
        $this->assertSame('REUSE', $plan['idempotency']['same_key_same_hash']);
        $this->assertTrue($plan['reconciliation_required']);
        $this->assertSame($plan['payload_hash'], CreditPurchaseInventoryReversalContract::plan($this->source(), ['reason' => 'คืนสินค้าตามใบลดหนี้', 'date' => '2026-08-23'])['payload_hash']);
    }

    public function test_it_rejects_credit_purchase_without_exact_gr_scope(): void
    {
        $this->expectException(ValidationException::class);
        CreditPurchaseInventoryReversalContract::plan([...$this->source(), 'credit_receipt_line_id' => 8], ['reason' => 'คืนสินค้า']);
    }

    public function test_it_rejects_non_final_or_wrong_document_sources(): void
    {
        $this->expectException(ValidationException::class);
        CreditPurchaseInventoryReversalContract::plan([...$this->source(), 'allocation_cost_status' => 'PENDING'], ['reason' => 'คืนสินค้า']);
    }

    /** @return array<string, mixed> */
    private function source(): array
    {
        return [
            'credit_document_id' => 20, 'original_document_id' => 19, 'credit_journal_id' => 70,
            'movement_id' => 40, 'allocation_id' => 41, 'credit_journal_line_id' => 71, 'revision' => 0,
            'credit_document_status' => 'POSTED', 'credit_document_type' => 'CREDIT_NOTE', 'original_document_status' => 'POSTED',
            'movement_status' => 'POSTED', 'allocation_status' => 'POSTED', 'allocation_cost_status' => 'FINAL', 'credit_journal_status' => 'POSTED',
            'credit_warehouse_id' => 9, 'original_warehouse_id' => 9, 'movement_warehouse_id' => 9,
            'credit_supplier_id' => 12, 'original_supplier_id' => 12,
            'credit_receipt_line_id' => 55, 'original_receipt_line_id' => 55,
        ];
    }
}
