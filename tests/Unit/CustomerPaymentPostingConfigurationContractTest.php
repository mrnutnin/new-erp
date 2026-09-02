<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CustomerPaymentPostingConfigurationContractTest extends TestCase
{
    public function test_customer_payment_keeps_bank_and_open_item_sources_but_resolves_current_tax_roles_by_event(): void
    {
        $root = dirname(__DIR__, 2);
        $posting = file_get_contents($root.'/app/Modules/Finance/Services/SettlementPostingService.php');
        $vatLedger = file_get_contents($root.'/app/Modules/Finance/Services/VatRealizationLedgerService.php');
        $whtLedger = file_get_contents($root.'/app/Modules/Finance/Services/WhtRealizationLedgerService.php');

        self::assertStringContainsString("'source_type' => 'BANK_ACCOUNT'", $posting);
        self::assertStringContainsString("'source_type' => 'OPEN_ITEM'", $posting);
        self::assertStringContainsString("resolveForEvent('customer_payment', 'OUTPUT_VAT')", $posting);
        self::assertStringContainsString("resolveForEvent('customer_payment', 'WHT_RECEIVABLE')", $posting);
        self::assertStringContainsString("resolveForEvent('customer_payment', 'CUSTOMER_ADVANCE')", $posting);
        self::assertStringContainsString("resolveForEvent('supplier_payment', 'INPUT_VAT')", $posting);
        self::assertStringContainsString("resolveForEvent('supplier_payment', 'WHT_PAYABLE')", $posting);
        self::assertStringContainsString('sourceDeferredVatAccount', $posting);
        self::assertStringContainsString("resolveForEvent('customer_payment', 'OUTPUT_VAT')", $vatLedger);
        self::assertStringContainsString("resolveForEvent('customer_payment', 'WHT_RECEIVABLE')", $whtLedger);
        self::assertStringContainsString("resolveForEvent('supplier_payment', 'INPUT_VAT')", $vatLedger);
        self::assertStringContainsString("resolveForEvent('supplier_payment', 'WHT_PAYABLE')", $whtLedger);
    }
}
