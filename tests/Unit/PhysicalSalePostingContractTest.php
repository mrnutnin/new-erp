<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\PhysicalSalePostingContract;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PhysicalSalePostingContractTest extends TestCase
{
    public function test_hs_preview_returns_stock_issue_intents_in_line_order(): void
    {
        $result = PhysicalSalePostingContract::preview([
            'document_type' => 'HS', 'source_type' => 'SALES_ORDER', 'source_id' => 9,
            'warehouse_id' => 2, 'business_date' => '2026-08-26',
            'lines' => [
                ['line_number' => 2, 'item_id' => 8, 'uom_id' => 11, 'stock_uom_id' => 12, 'quantity' => '2', 'uom_factor' => '10'],
                ['line_number' => 1, 'item_id' => 7, 'uom_id' => 11, 'stock_uom_id' => 12, 'quantity' => '1', 'uom_factor' => '5'],
            ],
        ]);

        $this->assertSame([1, 2], array_column($result['lines'], 'line_number'));
        $this->assertSame('20.00000000', $result['lines'][1]['stock_quantity']);
    }

    public function test_physical_sale_rejects_service_line_and_duplicate_line_number(): void
    {
        $this->expectException(ValidationException::class);
        PhysicalSalePostingContract::preview([
            'document_type' => 'IV', 'source_type' => 'SALES_ORDER', 'source_id' => 9,
            'warehouse_id' => 2, 'business_date' => '2026-08-26',
            'lines' => [
                ['line_number' => 1, 'item_id' => 7, 'uom_id' => 11, 'stock_uom_id' => 12, 'quantity' => '1', 'uom_factor' => '1'],
                ['line_number' => 1, 'description' => 'บริการ'],
            ],
        ]);
    }
}
