<?php

namespace Tests\Unit;

use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Pos\Support\PhysicalSaleJournalPostingIntent;
use Tests\TestCase;

final class PhysicalSaleRevenuePostingPlanTest extends TestCase
{
    public function test_resolved_item_revenue_accounts_produce_a_balanced_none_vat_intent(): void
    {
        $journal = PhysicalSaleJournalPostingIntent::build([
            'id' => 42, 'document_type' => 'HS', 'warehouse_id' => 3, 'party_id' => 9,
            'ar_account_id' => 1100, 'document_number' => 'HS-000042', 'document_date' => '2026-08-28',
            'posting_date' => '2026-08-28', 'tax_amount' => '0.00',
            'lines' => [
                ['line_number' => 1, 'line_total' => '100.00', 'revenue_account_id' => 4100],
                ['line_number' => 2, 'line_total' => '150.00', 'revenue_account_id' => 4200],
            ],
        ]);

        $totals = JournalBalance::totals($journal['lines']);

        $this->assertSame(25000, $totals['debit']);
        $this->assertSame($totals['debit'], $totals['credit']);
        $this->assertSame([1100, 4100, 4200], array_column($journal['lines'], 'account_id'));
    }
}
