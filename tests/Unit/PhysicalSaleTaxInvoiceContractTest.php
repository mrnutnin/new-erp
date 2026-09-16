<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PhysicalSaleTaxInvoiceContractTest extends TestCase
{
    public function test_hs_selects_and_snapshots_the_tax_invoice_before_posting(): void
    {
        $root = dirname(__DIR__, 2);
        $request = file_get_contents($root.'/app/Modules/Pos/Requests/PostPhysicalSaleRequest.php');
        $controller = file_get_contents($root.'/app/Modules/Pos/Controllers/PhysicalSaleController.php');
        $view = file_get_contents($root.'/app/Modules/Pos/Views/physical-sales/show.blade.php');

        self::assertStringContainsString("'tax_invoice_type' => ['nullable', 'in:ABBREVIATED,FULL']", $request);
        self::assertStringContainsString('snapshotTaxInvoice($draft, $request->validated(\'tax_invoice_type\'), $settings)', $controller);
        self::assertStringContainsString('issuer_tax_branch_code', $controller);
        self::assertStringContainsString('name="tax_invoice_type"', $view);
        self::assertStringContainsString('ใบกำกับภาษีเต็มรูป', $view);
        self::assertStringContainsString('ใบกำกับภาษีอย่างย่อ', $view);
        self::assertStringContainsString('เอกสารภาษีที่เลือก', $view);
        self::assertStringContainsString("load(['branch', 'warehouse'", $controller);

        $pdf = file_get_contents($root.'/app/Modules/Pos/Views/pdf/physical-sale.blade.php');
        self::assertStringContainsString('ช่องทางการชำระเงิน', file_get_contents($root.'/app/Modules/Pos/Views/pdf/partials/physical-sale-payments.blade.php'));
        self::assertStringContainsString('party_address', $pdf);
        self::assertStringContainsString('$hasAdvanceDeposit = (float) $paymentSummary[\'deposit_total\'] > 0;', $pdf);
        self::assertStringContainsString('@if($hasAdvanceDeposit)', $pdf);
    }
}
