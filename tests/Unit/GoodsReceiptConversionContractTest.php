<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\GoodsReceiptConversionContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class GoodsReceiptConversionContractTest extends TestCase
{
    public function test_converts_purchase_quantity_and_snapshots_effective_conversion(): void
    {
        $result = GoodsReceiptConversionContract::resolve([
            'purchase_qty' => '2', 'purchase_uom_id' => 10, 'stock_uom_id' => 20,
            'business_date' => '2026-08-22', 'total_cost' => '100.00',
            'conversion_candidates' => [[
                'id' => 7, 'from_uom_id' => 10, 'to_uom_id' => 20, 'factor' => '12.5',
                'is_active' => true, 'effective_from' => '2026-01-01', 'effective_to' => null,
            ]],
        ]);

        $this->assertSame('25.00000000', $result['stock_quantity']);
        $this->assertSame('4.00000000', $result['stock_unit_cost']);
        $this->assertSame('12.50000000', (string) $result['snapshot']['factor']);
        $this->assertSame(7, $result['snapshot']['conversion_id']);
    }

    public function test_rejects_missing_or_duplicate_effective_conversion(): void
    {
        $base = [
            'purchase_qty' => '1', 'purchase_uom_id' => 10, 'stock_uom_id' => 20,
            'business_date' => '2026-08-22', 'total_cost' => '10',
        ];
        $this->expectException(ValidationException::class);
        GoodsReceiptConversionContract::resolve([...$base, 'conversion_candidates' => []]);
    }

    public function test_rejects_duplicate_active_conversion_and_zero_factor(): void
    {
        $candidate = ['from_uom_id' => 10, 'to_uom_id' => 20, 'factor' => '2', 'is_active' => true, 'effective_from' => '2026-01-01'];
        try {
            GoodsReceiptConversionContract::resolve([
                'purchase_qty' => '1', 'purchase_uom_id' => 10, 'stock_uom_id' => 20,
                'business_date' => '2026-08-22', 'total_cost' => '10',
                'conversion_candidates' => [$candidate, [...$candidate, 'id' => 2]],
            ]);
            $this->fail('Expected duplicate conversion to be rejected');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
        $this->expectException(ValidationException::class);
        GoodsReceiptConversionContract::resolve([
            'purchase_qty' => '1', 'purchase_uom_id' => 10, 'stock_uom_id' => 20,
            'business_date' => '2026-08-22', 'total_cost' => '10',
            'conversion_candidates' => [[...$candidate, 'factor' => '0']],
        ]);
    }

    public function test_same_uom_uses_factor_one_without_conversion_lookup(): void
    {
        $result = GoodsReceiptConversionContract::resolve([
            'purchase_qty' => '3', 'purchase_uom_id' => 10, 'stock_uom_id' => 10,
            'business_date' => '2026-08-22', 'total_cost' => '12',
        ]);

        $this->assertSame('3.00000000', $result['stock_quantity']);
        $this->assertSame('1.00000000', $result['snapshot']['factor']);
        $this->assertNull($result['snapshot']['conversion_id']);
    }

    public function test_backdated_receipt_uses_the_conversion_effective_on_receipt_date(): void
    {
        $result = GoodsReceiptConversionContract::resolve([
            'purchase_qty' => '2', 'purchase_uom_id' => 10, 'stock_uom_id' => 20,
            'business_date' => '2026-02-15', 'total_cost' => '20',
            'conversion_candidates' => [
                ['id' => 1, 'from_uom_id' => 10, 'to_uom_id' => 20, 'factor' => '10', 'is_active' => true, 'effective_from' => '2026-01-01', 'effective_to' => '2026-06-30'],
            ],
        ]);

        $this->assertSame('20.00000000', $result['stock_quantity']);
        $this->assertSame(1, $result['snapshot']['conversion_id']);
        $this->assertSame('2026-06-30', $result['snapshot']['effective_to']);
    }

    public function test_backdated_receipt_rejects_a_conversion_that_was_not_yet_effective(): void
    {
        $this->expectException(ValidationException::class);
        GoodsReceiptConversionContract::resolve([
            'purchase_qty' => '2', 'purchase_uom_id' => 10, 'stock_uom_id' => 20,
            'business_date' => '2025-12-31', 'total_cost' => '20',
            'conversion_candidates' => [
                ['id' => 1, 'from_uom_id' => 10, 'to_uom_id' => 20, 'factor' => '10', 'is_active' => true, 'effective_from' => '2026-01-01', 'effective_to' => null],
            ],
        ]);
    }

    public function test_rejects_calendar_invalid_business_date(): void
    {
        $this->expectException(ValidationException::class);
        GoodsReceiptConversionContract::resolve([
            'purchase_qty' => '1', 'purchase_uom_id' => 10, 'stock_uom_id' => 10,
            'business_date' => '2026-02-30', 'total_cost' => '10',
        ]);
    }
}
