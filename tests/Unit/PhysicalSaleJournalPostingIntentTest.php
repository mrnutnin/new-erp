<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\PhysicalSaleJournalPostingIntent;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PhysicalSaleJournalPostingIntentTest extends TestCase
{
    public function test_builds_ar_debit_and_grouped_revenue_credits_in_stable_order(): void
    {
        $result = PhysicalSaleJournalPostingIntent::build([
            'id' => 12, 'document_type' => 'HS', 'warehouse_id' => 3, 'party_id' => 8,
            'ar_account_id' => 1100, 'document_number' => 'HS-000012', 'document_date' => '2026-08-27',
            'tax_amount' => '0.00',
            'lines' => [
                ['line_number' => 2, 'sales_account_id' => 4200, 'line_total' => '25.50'],
                ['line_number' => 1, 'sales_account_id' => 4100, 'line_total' => '100.00'],
                ['line_number' => 3, 'sales_account_id' => 4100, 'line_total' => '4.50'],
            ],
        ]);

        $this->assertSame('sales_invoice', $result['event_code']);
        $this->assertSame('130.00', $result['lines'][0]['debit']);
        $this->assertSame([1100, 4100, 4200], array_column($result['lines'], 'account_id'));
        $this->assertSame('104.50', $result['lines'][1]['credit']);
        $this->assertSame('25.50', $result['lines'][2]['credit']);
    }

    public function test_rejects_non_none_vat(): void
    {
        $this->expectException(ValidationException::class);
        PhysicalSaleJournalPostingIntent::build([
            'id' => 1, 'document_type' => 'IV', 'warehouse_id' => 1, 'party_id' => 2,
            'ar_account_id' => 1100, 'document_number' => 'IV-1', 'business_date' => '2026-08-27',
            'tax_amount' => '7.00', 'lines' => [['line_number' => 1, 'sales_account_id' => 4100, 'line_total' => '100.00']],
        ]);
    }
}
