<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Support\PurchaseReturnPostingContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PurchaseReturnPostingContractTest extends TestCase
{
public function test_it_creates_an_idempotent_purchase_return_credit_note_plan(): void
{
    $plan = PurchaseReturnPostingContract::plan([
        'purchase_return_id' => 10, 'purchase_document_id' => 20, 'return_status' => 'APPROVED',
        'invoice_status' => 'POSTED', 'invoice_type' => 'INVOICE', 'return_warehouse_id' => 1,
        'invoice_warehouse_id' => 1, 'return_supplier_id' => 2, 'invoice_supplier_id' => 2,
        'credit_note_id' => null, 'gross_amount' => '100.00',
    ]);
    self::assertSame('purchase-return:10:credit-note', $plan['idempotency_key']);
    self::assertContains('link_credit_note', $plan['steps']);
}

public function test_it_rejects_a_return_without_a_posted_source_invoice(): void
{
    $this->expectException(ValidationException::class);
    PurchaseReturnPostingContract::plan([
        'purchase_return_id' => 10, 'purchase_document_id' => 20, 'return_status' => 'APPROVED',
        'invoice_status' => 'DRAFT', 'invoice_type' => 'INVOICE', 'return_warehouse_id' => 1,
        'invoice_warehouse_id' => 1, 'return_supplier_id' => 2, 'invoice_supplier_id' => 2,
        'credit_note_id' => null, 'gross_amount' => '100.00',
    ]);
}
}
