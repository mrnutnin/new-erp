<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\PhysicalSalePostingReadiness;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhysicalSalePostingReadinessTest extends TestCase
{
    public function test_accepts_draft_none_vat_sale_with_balanced_lines(): void
    {
        $sale = $this->sale();
        $sale['lines'][0]['stock_quantity'] = '2.00000000';
        $ready = PhysicalSalePostingReadiness::assertReady($sale);

        $this->assertSame(42, $ready['sale_id']);
        $this->assertSame(2, $ready['line_count']);
        $this->assertSame('250.00', $ready['total_amount']);
    }

    public function test_rejects_posted_or_unbalanced_sale(): void
    {
        $sale = $this->sale();
        $sale['status'] = 'POSTED';
        $this->expectException(ValidationException::class);
        PhysicalSalePostingReadiness::assertReady($sale);

        $sale = $this->sale();
        $sale['total_amount'] = '251.00';
        $this->expectException(ValidationException::class);
        PhysicalSalePostingReadiness::assertReady($sale);
    }

    public function test_accepts_zero_value_gift_lines_when_stock_quantity_is_positive(): void
    {
        $sale = $this->sale();
        $sale['total_amount'] = '0.00';
        foreach ($sale['lines'] as &$line) {
            $line['line_total'] = '0.00';
        }

        $ready = PhysicalSalePostingReadiness::assertReady($sale);

        $this->assertSame('0.00', $ready['total_amount']);
    }

    private function sale(): array
    {
        return [
            'id' => 42, 'document_number' => 'HS-000042', 'document_type' => 'HS',
            'source_type' => 'SALES_ORDER', 'source_id' => 7, 'warehouse_id' => 3,
            'status' => 'DRAFT', 'document_date' => '2026-08-27', 'posting_date' => '2026-08-27',
            'tax_amount' => '0.00', 'total_amount' => '250.00',
            'lines' => [
                ['line_number' => 1, 'item_id' => 11, 'stock_uom_id' => 1, 'stock_quantity' => '2', 'line_total' => '100.00'],
                ['line_number' => 2, 'item_id' => 12, 'stock_uom_id' => 1, 'stock_quantity' => '3', 'line_total' => '150.00'],
            ],
        ];
    }
}
