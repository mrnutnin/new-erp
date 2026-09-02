<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleCashPostingContractTest extends TestCase
{
    public function test_hs_posts_cash_directly_and_reverses_the_same_journal(): void
    {
        $root = dirname(__DIR__, 2);
        $plan = file_get_contents($root.'/app/Modules/Pos/Support/PhysicalSaleRevenuePostingPlan.php');
        $posting = file_get_contents($root.'/app/Modules/Pos/Services/PhysicalSalePostingService.php');
        $cancellation = file_get_contents($root.'/app/Modules/Pos/Services/PhysicalSaleCancellationService.php');
        $receiptController = file_get_contents($root.'/app/Modules/Pos/Controllers/PhysicalSaleReceiptController.php');

        self::assertStringContainsString("'event_code' => 'sales_invoice'", $plan);
        self::assertStringContainsString("resolveForEvent('sales_invoice', 'WHT_RECEIVABLE')", $plan);
        self::assertStringContainsString("resolveForEvent('sales_invoice', 'CUSTOMER_ADVANCE')", $plan);
        self::assertStringContainsString("'source_type' => 'BANK_ACCOUNT'", $plan);
        self::assertStringContainsString("'source_type' => 'ITEM'", $plan);
        self::assertStringContainsString("'posting_metadata'", $plan);
        self::assertStringContainsString("if (\$sale->document_type === 'IV')", $posting);
        self::assertStringContainsString('$sale->tenders()->create', $posting);
        self::assertStringContainsString("if (\$sale->document_type === 'HS')", $cancellation);
        self::assertStringContainsString('reverseWithinTransaction($revenue', $cancellation);
        self::assertStringContainsString("\$physicalSale->document_type !== 'IV'", $receiptController);
    }
}
