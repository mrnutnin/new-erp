<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\PurchaseThreeWayMatchContract;
use App\Modules\Wms\Support\PurchaseThreeWayMatchPolicy;
use App\Modules\Wms\Support\PurchaseThreeWayMatchService;
use Tests\TestCase;

final class PurchaseThreeWayMatchContractTest extends TestCase
{
    public function test_matching_po_receipt_and_invoice_is_ready_with_stable_source(): void
    {
        $result = PurchaseThreeWayMatchContract::evaluate($this->po(), [$this->receipt(4, 400)], $this->invoice(4, 100));

        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame('PURCHASE_DOCUMENT_WITH_EXPLICIT_PO_LINE_AND_GOODS_RECEIPT_LINE', $result['source_of_truth']);
        $this->assertSame('purchase-3way:po:10:document:30:revision:0', $result['idempotency_key']);
        $this->assertSame('4.00000000', $result['lines'][0]['received_quantity']);
    }

    public function test_quantity_price_and_identity_variances_block_matching(): void
    {
        $receipt = $this->receipt(11, 1210);
        $invoice = $this->invoice(12, 110);

        $result = PurchaseThreeWayMatchContract::evaluate($this->po(), [$receipt], $invoice);

        $this->assertFalse($result['ready']);
        $this->assertContains('receipt_exceeds_po_quantity', $result['blockers']);
        $this->assertContains('invoice_exceeds_received_quantity', $result['blockers']);
        $this->assertContains('invoice_price_variance', $result['blockers']);
    }

    public function test_missing_line_linkage_and_credit_note_are_blocked(): void
    {
        $invoice = $this->invoice(4, 100);
        unset($invoice['lines'][0]['purchase_order_line_id']);
        $invoice['document_type'] = 'CREDIT_NOTE';

        $result = PurchaseThreeWayMatchContract::evaluate($this->po(), [$this->receipt(4, 400)], $invoice);

        $this->assertFalse($result['ready']);
        $this->assertContains('purchase_document_not_invoice', $result['blockers']);
        $this->assertContains('purchase_document_line_identity_required', $result['blockers']);
    }

    public function test_expense_line_can_remain_unlinked_until_matching_policy_is_applied(): void
    {
        $invoice = $this->invoice(1, 100);
        $invoice['lines'][0]['item_id'] = null;
        $invoice['lines'][0]['uom_id'] = null;
        unset($invoice['lines'][0]['purchase_order_line_id']);

        $result = PurchaseThreeWayMatchContract::evaluate($this->po(), [], $invoice);

        $this->assertContains('purchase_document_line_identity_required', $result['blockers']);
        $this->assertFalse($result['ready']);
    }

    public function test_inventory_document_requires_persisted_receipt_allocation(): void
    {
        $invoice = $this->invoice(4, 100);
        unset($invoice['lines'][0]['receipt_allocations']);
        $result = (new PurchaseThreeWayMatchService)->evaluate($this->po(), [$this->receipt(4, 400)], $invoice);

        $this->assertContains('inventory_line_receipt_allocation_required', $result['blockers']);
        $this->assertSame('CLEAR', $result['variance_state']);
    }

    public function test_allocation_must_point_to_same_po_line_and_policy_can_request_approval(): void
    {
        $invoice = $this->invoice(4, 101);
        $invoice['lines'][0]['receipt_allocations'] = [['goods_receipt_line_id' => 200, 'allocated_quantity' => '4']];
        $policy = new PurchaseThreeWayMatchPolicy(priceTolerance: '0.01', blockOnVariance: false);
        $result = (new PurchaseThreeWayMatchService)->evaluate($this->po(), [$this->receipt(4, 400)], $invoice, $policy);

        $this->assertTrue($result['variance_requires_approval']);
        $this->assertSame('APPROVAL_REQUIRED', $result['variance_state']);
        $this->assertContains('invoice_price_variance', $result['blockers']);
    }

    private function po(): array
    {
        return [
            'id' => 10, 'warehouse_id' => 2, 'supplier_id' => 7, 'status' => 'APPROVED',
            'lines' => [['id' => 100, 'item_id' => 4, 'uom_id' => 5, 'quantity' => '10', 'unit_price' => '100']],
        ];
    }

    private function receipt(string|int $quantity, string|int $cost): array
    {
        return [
            'id' => 20, 'purchase_order_id' => 10, 'warehouse_id' => 2, 'supplier_id' => 7, 'status' => 'APPROVED',
            'lines' => [['id' => 200, 'purchase_order_line_id' => 100, 'item_id' => 4, 'purchase_uom_id' => 5, 'purchase_quantity' => (string) $quantity, 'total_cost' => (string) $cost]],
        ];
    }

    private function invoice(string|int $quantity, string|int $unitPrice): array
    {
        return [
            'id' => 30, 'warehouse_id' => 2, 'supplier_id' => 7, 'document_type' => 'INVOICE', 'status' => 'APPROVED',
            'lines' => [['id' => 300, 'purchase_order_line_id' => 100, 'item_id' => 4, 'uom_id' => 5, 'quantity' => (string) $quantity, 'unit_price' => (string) $unitPrice, 'receipt_allocations' => [['goods_receipt_line_id' => 200, 'allocated_quantity' => (string) $quantity]]]],
        ];
    }
}
