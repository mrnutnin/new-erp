<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleVatWhtCancellationContractTest extends TestCase
{
    public function test_full_cancellation_keeps_vat_wht_and_requires_receipt_reversal_first(): void
    {
        $root = dirname(__DIR__, 2);
        $cancellation = file_get_contents($root.'/app/Modules/Pos/Services/PhysicalSaleCancellationService.php');
        $writer = file_get_contents($root.'/app/Modules/Accounting/Services/JournalEntryWriter.php');

        self::assertStringNotContainsString('รองรับเฉพาะ NONE_VAT', $cancellation);
        self::assertStringContainsString('assertNoPostedReceipts', $cancellation);
        self::assertStringNotContainsString('$this->settlements->reverse(', $cancellation);
        self::assertStringContainsString("'event_code' => 'sales_credit_note'", $cancellation);
        self::assertStringContainsString("'tax_code_id' => \$line->tax_code_id", $writer);
        self::assertStringContainsString("'tax_amount' => \$line->tax_amount", $writer);
        self::assertStringContainsString("'tax_settlement_date' => \$line->tax_settlement_date?->format('Y-m-d')", $writer);
    }
}
