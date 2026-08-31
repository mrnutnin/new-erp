<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSalePaymentStatusContractTest extends TestCase
{
    public function test_posted_physical_sale_payment_status_uses_its_ar_open_item_balance(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/PhysicalSaleController.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/physical-sales/index.blade.php');

        self::assertStringContainsString("where('open_items.ledger_type', 'AR')", $controller);
        self::assertStringContainsString('finance_advance_deposit_applications', $controller);
        self::assertStringContainsString('payment_remaining', $controller);
        self::assertStringContainsString("\$sale->status !== 'POSTED'", $controller);
        self::assertStringContainsString("\$sale->document_type === 'HS' || JournalBalance::decimal(\$sale->total_amount) === '0.00'", $controller);
        self::assertStringContainsString("'CHECK' => \$query->where('pos_physical_sales.document_type', 'IV')", $controller);
        self::assertStringContainsString("'PARTIAL'", $controller);
        self::assertStringContainsString("'payment_status' => ['nullable', 'in:UNPAID,PARTIAL,PAID,CHECK']", $controller);
        self::assertStringContainsString("where('pos_physical_sales.status', 'POSTED')", $controller);
        self::assertStringContainsString('สถานะการชำระ', $view);
        self::assertStringContainsString('payment_status_label', $view);
        self::assertStringContainsString('physical-sale-filter', $view);
        self::assertStringContainsString('physical-sale-from', $view);
        self::assertStringContainsString('receive_payment_url', $controller);
        self::assertStringContainsString('cancel_full_detail_url', $controller);
        self::assertStringContainsString("button(row.pdf_url,'btn-app-soft','bx-printer','พิมพ์ PDF')", $view);
        self::assertStringContainsString("button(row.receive_payment_url,'btn-success','bx-money','รับชำระเงิน')", $view);
    }
}
