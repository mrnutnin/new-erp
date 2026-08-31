<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class SalesInvoicePaymentStatusContractTest extends TestCase
{
    public function test_posted_invoice_payment_status_uses_ar_open_item_balance(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/SalesDocumentController.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/sales-documents/show.blade.php');
        $listView = file_get_contents($root.'/app/Modules/Pos/Views/sales-documents/index.blade.php');

        $this->assertStringContainsString('$document->document_type === \'INVOICE\' && $document->status === \'POSTED\'', $controller);
        $this->assertStringContainsString('remainingAt($openItem, today()->format', $controller);
        $this->assertStringContainsString('สถานะการรับชำระ', $view);
        $this->assertStringContainsString("route('finance.settlements.create', ['open_item_id'", $view);
        $this->assertStringContainsString('payment_remaining', $controller);
        $this->assertStringContainsString('finance_advance_deposit_applications', $controller);
        $this->assertStringContainsString('payment_status', $listView);
    }
}
