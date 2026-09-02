<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleCancellationServiceContractTest extends TestCase
{
    public function test_it_blocks_an_invoice_cancellation_when_a_receipt_is_already_posted(): void
    {
        $service = file_get_contents(dirname(__DIR__, 2).'/app/Modules/Pos/Services/PhysicalSaleCancellationService.php');
        self::assertStringContainsString('return DB::transaction(', $service);
        self::assertStringContainsString("'event_code' => 'sales_credit_note'", $service);
        self::assertStringContainsString("'document_type' => 'CREDIT_NOTE'", $service);
        self::assertStringContainsString('$this->openItems->allocate(', $service);
        self::assertStringContainsString('assertNoPostedReceipts', $service);
        self::assertStringContainsString("->where('status', 'POSTED')", $service);
        self::assertStringContainsString('กรุณายกเลิกเอกสารรับชำระหนี้ก่อน', $service);
        self::assertStringContainsString('$this->movements->reverseWithinTransaction(', $service);
        self::assertStringContainsString('$this->journals->reverseWithinTransaction(', $service);
        self::assertStringContainsString("'posting_metadata' => \$this->originalRevenueMetadata(\$revenue)", $service);
        self::assertStringContainsString("'source' => 'ORIGINAL'", $service);
        self::assertStringContainsString("'status' => 'VOID'", $service);
    }
}
