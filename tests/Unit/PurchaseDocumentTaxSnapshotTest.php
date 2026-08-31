<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\PurchaseDocumentCalculator;
use PHPUnit\Framework\TestCase;

final class PurchaseDocumentTaxSnapshotTest extends TestCase
{
    public function test_vat_in_exclusive_calculation_snapshots_base_tax_and_gross(): void
    {
        $result = PurchaseDocumentCalculator::calculate([['description' => 'Service', 'quantity' => '1', 'unit_price' => '100', 'discount_amount' => '0', 'tax_rate' => '7']], 'VAT_IN');
        $this->assertSame('100.00', $result['subtotal']);
        $this->assertSame('7.00', $result['tax_amount']);
        $this->assertSame('107.00', $result['gross_amount']);
        $this->assertSame('7.00', $result['lines'][0]['tax_amount']);
    }

    public function test_vat_in_inclusive_keeps_gross_input(): void
    {
        $result = PurchaseDocumentCalculator::calculate([['description' => 'Service', 'quantity' => '1', 'unit_price' => '107', 'discount_amount' => '0', 'tax_rate' => '7']], 'VAT_IN', true);
        $this->assertSame('100.00', $result['subtotal']);
        $this->assertSame('7.00', $result['tax_amount']);
        $this->assertSame('107.00', $result['gross_amount']);
    }
}
