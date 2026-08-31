<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SettlementInvoiceReceiptPrefillContractTest extends TestCase
{
    public function test_invoice_receipt_link_prefills_the_remaining_open_item(): void
    {
        $root = dirname(__DIR__, 2);
        $settlements = file_get_contents($root.'/app/Modules/Finance/Controllers/SettlementController.php');
        $salesView = file_get_contents($root.'/app/Modules/Pos/Views/sales-documents/show.blade.php');

        $this->assertStringContainsString("\$request->integer('open_item_id')", $settlements);
        $this->assertStringContainsString('remainingAt($preselectedOpenItem', $settlements);
        $this->assertStringContainsString("'open_item_id' => \$paymentOpenItem->id", $salesView);
    }
}
