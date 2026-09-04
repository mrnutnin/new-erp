<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Support\PurchaseReturnPartialMultiLayerJournalLinkContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PurchaseReturnPartialMultiLayerJournalLinkContractTest extends TestCase
{
    public function test_it_plans_multiple_layers_against_one_credit_journal_line(): void
    {
        $plan = PurchaseReturnPartialMultiLayerJournalLinkContract::plan([
            'purchase_return_id' => 7, 'credit_note_id' => 8, 'journal_entry_id' => 9, 'journal_line_id' => 10,
            'allocation_ids' => [40, 41], 'return_status' => 'APPROVED', 'credit_status' => 'POSTED',
            'journal_status' => 'POSTED', 'journal_event' => 'purchase_credit_note', 'journal_source_id' => 8,
            'credit_warehouse_id' => 2, 'return_warehouse_id' => 2, 'credit_supplier_id' => 3, 'return_supplier_id' => 3,
            'allocation_account_id' => 99, 'journal_account_id' => 99, 'allocation_total' => '-250.00', 'journal_line_credit' => '250.00',
        ]);

        self::assertSame([40, 41], $plan['allocation_ids']);
        self::assertTrue($plan['atomic']);
    }

    public function test_it_rejects_a_total_that_does_not_match_the_credit_line(): void
    {
        $this->expectException(ValidationException::class);

        PurchaseReturnPartialMultiLayerJournalLinkContract::plan([
            'purchase_return_id' => 7, 'credit_note_id' => 8, 'journal_entry_id' => 9, 'journal_line_id' => 10,
            'allocation_ids' => [40, 41], 'return_status' => 'APPROVED', 'credit_status' => 'POSTED',
            'journal_status' => 'POSTED', 'journal_event' => 'purchase_credit_note', 'journal_source_id' => 8,
            'credit_warehouse_id' => 2, 'return_warehouse_id' => 2, 'credit_supplier_id' => 3, 'return_supplier_id' => 3,
            'allocation_account_id' => 99, 'journal_account_id' => 99, 'allocation_total' => '-249.99', 'journal_line_credit' => '250.00',
        ]);
    }
}
