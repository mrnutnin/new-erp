<?php

namespace Tests\Unit;

use App\Modules\Finance\Support\VatRealizationJournalLines;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VatRealizationJournalLinesTest extends TestCase
{
    public function test_input_vat_debits_actual_and_credits_deferred(): void
    {
        $lines = VatRealizationJournalLines::build('VAT_IN', 11810, 11800, '50.00', '3.50', 10, '2026-08-01', '2026-08-05');
        $this->assertSame(11800, $lines[0]['account_id']);
        $this->assertSame('3.50', $lines[0]['debit']);
        $this->assertSame(11810, $lines[1]['account_id']);
        $this->assertSame('3.50', $lines[1]['credit']);
    }

    public function test_output_vat_debits_deferred_and_credits_actual(): void
    {
        $lines = VatRealizationJournalLines::build('VAT_OUT', 21810, 21800, '50.00', '3.50', 11, '2026-08-01', '2026-08-05');
        $this->assertSame(21810, $lines[0]['account_id']);
        $this->assertSame(21800, $lines[1]['account_id']);
        $this->assertSame('2026-08-05', $lines[0]['tax_settlement_date']);
    }

    public function test_zero_tax_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        VatRealizationJournalLines::build('VAT_IN', 1, 2, '0.00', '0.00', 1, '2026-08-01', '2026-08-05');
    }
}
