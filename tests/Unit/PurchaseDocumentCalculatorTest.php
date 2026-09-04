<?php

namespace Tests\Unit;

use App\Modules\Purchasing\Support\PurchaseDocumentCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PurchaseDocumentCalculatorTest extends TestCase
{
    public function test_it_calculates_none_vat_lines_without_float_math(): void
    {
        $result = PurchaseDocumentCalculator::calculate([
            ['quantity' => '2.5000', 'unit_price' => '100.1250', 'discount_amount' => '0.31'],
            ['quantity' => '1', 'unit_price' => '50', 'discount_amount' => '0'],
        ]);

        $this->assertSame('2.5000', $result['lines'][0]['quantity']);
        $this->assertSame('100.1250', $result['lines'][0]['unit_price']);
        $this->assertSame('250.00', $result['lines'][0]['net_amount']);
        $this->assertSame('300.00', $result['gross_amount']);
        $this->assertSame('0.00', $result['tax_amount']);
    }

    public function test_it_rejects_discount_above_line_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PurchaseDocumentCalculator::calculate([
            ['quantity' => '1', 'unit_price' => '10', 'discount_amount' => '10.01'],
        ]);
    }

    public function test_it_preserves_explicit_inventory_lineage_fields(): void
    {
        $result = PurchaseDocumentCalculator::calculate([
            ['item_id' => 4, 'uom_id' => 2, 'quantity' => '1', 'unit_price' => '10', 'discount_amount' => '0'],
        ]);

        $this->assertSame(4, $result['lines'][0]['item_id']);
        $this->assertSame(2, $result['lines'][0]['uom_id']);
    }
}
