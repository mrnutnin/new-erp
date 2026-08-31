<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SettlementPhysicalSaleReceiptPrefillContractTest extends TestCase
{
    public function test_receipt_prefill_keeps_the_same_ar_scope_and_store_rechecks_availability(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Finance/Controllers/SettlementController.php');

        $this->assertStringContainsString("->where('warehouse_id', \$warehouseId)", $controller);
        $this->assertStringContainsString("->where('ledger_type', 'AR')", $controller);
        $this->assertStringContainsString("->where('party_type', 'CUSTOMER')", $controller);
        $this->assertStringContainsString("->where('balance_side', 'DEBIT')", $controller);
        $this->assertStringContainsString("->whereDate('posting_date', '<=', today())", $controller);
        $this->assertStringContainsString('remainingAt($preselectedOpenItem', $controller);
        $this->assertStringContainsString('withholdingFor($preselectedOpenItem', $controller);
        $this->assertStringContainsString('WhtRealizationCalculator::calculate(', $controller);
        $this->assertStringContainsString('lockForUpdate()', $controller);
        $this->assertStringContainsString('assertAmountAvailable($item', $controller);
    }
}
