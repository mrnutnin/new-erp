<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Support\PurchaseReturnPartialJournalLinkContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PurchaseReturnPartialJournalLinkContractTest extends TestCase
{
    public function test_partial_cost_allocation_can_link_to_matching_credit_journal_line(): void
    {
        $plan = PurchaseReturnPartialJournalLinkContract::plan([
            'purchase_return_id' => 10, 'credit_note_id' => 20, 'journal_entry_id' => 30, 'allocation_id' => 40, 'journal_line_id' => 50,
            'return_status' => 'APPROVED', 'credit_status' => 'POSTED', 'journal_status' => 'POSTED', 'journal_event' => 'purchase_credit_note',
            'journal_source_id' => 20, 'credit_warehouse_id' => 1, 'return_warehouse_id' => 1, 'credit_supplier_id' => 2, 'return_supplier_id' => 2,
            'allocation_account_id' => 7, 'journal_account_id' => 7, 'allocation_value' => '-250.00000000', 'journal_line_credit' => '250.00000000',
        ]);

        self::assertSame('purchase-return:10:partial-journal:50', $plan['idempotency_key']);
        self::assertTrue($plan['atomic']);
    }

    public function test_partial_link_rejects_different_amounts(): void
    {
        $this->expectException(ValidationException::class);
        PurchaseReturnPartialJournalLinkContract::plan([
            'purchase_return_id' => 10, 'credit_note_id' => 20, 'journal_entry_id' => 30, 'allocation_id' => 40, 'journal_line_id' => 50,
            'return_status' => 'APPROVED', 'credit_status' => 'POSTED', 'journal_status' => 'POSTED', 'journal_event' => 'purchase_credit_note',
            'journal_source_id' => 20, 'credit_warehouse_id' => 1, 'return_warehouse_id' => 1, 'credit_supplier_id' => 2, 'return_supplier_id' => 2,
            'allocation_account_id' => 7, 'journal_account_id' => 7, 'allocation_value' => '-250.00000000', 'journal_line_credit' => '249.99000000',
        ]);
    }
}
