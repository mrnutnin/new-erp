<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PosReceiptPdfContractTest extends TestCase
{
    public function test_pos_receipt_pdf_is_scoped_and_print_permissioned(): void
    {
        $base = base_path();
        $routes = file_get_contents($base.'/app/Modules/Pos/Routes/web.php');
        $controller = file_get_contents($base.'/app/Modules/Pos/Controllers/ReceiptController.php');
        $view = file_get_contents($base.'/app/Modules/Pos/Views/pdf/receipt.blade.php');

        self::assertStringContainsString("Route::get('/receipts/{receipt}/pdf'", $routes);
        self::assertStringContainsString('permission:pos.receipts.print', $routes);
        self::assertStringContainsString("renderView('Pos::pdf.receipt'", $controller);
        self::assertStringContainsString('DocumentPdfRenderer', $controller);
        self::assertStringContainsString('allocationIntents.openItem', $controller);
        self::assertStringContainsString('tenders.bankAccount', $controller);
        self::assertStringContainsString('ใบเสร็จรับเงิน', $view);
        self::assertStringContainsString('เอกสารนี้ไม่ใช่ใบกำกับภาษี', $view);
    }
}
