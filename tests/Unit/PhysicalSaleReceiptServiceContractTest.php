<?php

namespace Tests\Unit;

use Tests\TestCase;

class PhysicalSaleReceiptServiceContractTest extends TestCase
{
    public function test_pos_receipt_reuses_the_posted_finance_settlement_contract(): void
    {
        $source = file_get_contents(base_path('app/Modules/Pos/Services/PhysicalSaleReceiptService.php'));

        $this->assertStringContainsString('PhysicalSale::query()->whereKey($sale->id)->lockForUpdate()', $source);
        $this->assertStringContainsString("->where('document_number', \$sale->document_number)", $source);
        $this->assertStringContainsString('->lockForUpdate()->firstOrFail()', $source);
        $this->assertStringContainsString("'document_type' => 'RECEIPT'", $source);
        $this->assertStringContainsString("'status' => 'APPROVED'", $source);
        $this->assertStringContainsString('$this->posting->post($settlement, $warehouse, $actor, $request)', $source);
        $this->assertStringContainsString('WhtRealizationCalculator::calculate(', $source);
        $this->assertStringContainsString("'tenders' => ['required', 'array', 'min:1', 'max:20']", $source);
        $this->assertStringNotContainsString('$allocation !== $remaining', $source);
        $this->assertStringContainsString('ยอดรับชำระเกินยอดคงเหลือของ HS/IV', $source);
    }
}
