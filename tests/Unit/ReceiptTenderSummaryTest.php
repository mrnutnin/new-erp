<?php

namespace Tests\Unit;

use App\Modules\Finance\Support\ReceiptTenderSummary;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ReceiptTenderSummaryTest extends TestCase
{
    public function test_cash_sale_accepts_multiple_tenders_and_keeps_excess_as_advance(): void
    {
        $summary = ReceiptTenderSummary::forCashSale('100.00', [['amount' => '40.00'], ['amount' => '70.00']]);

        $this->assertSame(['due_amount' => '100.00', 'withholding_amount' => '0.00', 'cash_due_amount' => '100.00', 'received_amount' => '110.00', 'allocated_amount' => '100.00', 'advance_amount' => '10.00'], $summary);
    }

    public function test_cash_sale_rejects_underpayment(): void
    {
        $this->expectException(ValidationException::class);

        ReceiptTenderSummary::forCashSale('100.00', [['amount' => '99.99']]);
    }

    public function test_cash_sale_uses_net_cash_after_withholding_and_preserves_overpayment(): void
    {
        $summary = ReceiptTenderSummary::forCashSale('100.00', [['amount' => '50.00'], ['amount' => '48.00']], '5.00');

        $this->assertSame('95.00', $summary['cash_due_amount']);
        $this->assertSame('100.00', $summary['allocated_amount']);
        $this->assertSame('3.00', $summary['advance_amount']);
    }
}
