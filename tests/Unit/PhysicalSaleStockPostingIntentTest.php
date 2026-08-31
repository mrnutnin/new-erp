<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\PhysicalSaleStockPostingIntent;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhysicalSaleStockPostingIntentTest extends TestCase
{
    public function test_builds_stock_uom_issue_intents_in_line_order_with_stable_keys(): void
    {
        $document = [
            'physical_sale_id' => 21, 'document_type' => 'HS', 'source_type' => 'SALES_ORDER',
            'source_id' => 8, 'warehouse_id' => 2, 'business_date' => '2026-08-26', 'document_number' => 'HS-000021',
            'lines' => [
                ['line_id' => 2, 'line_number' => 2, 'item_id' => 10, 'uom_id' => 4, 'stock_uom_id' => 1, 'quantity' => '2', 'factor' => '12', 'conversion_snapshot' => []],
                ['line_id' => 1, 'line_number' => 1, 'item_id' => 9, 'uom_id' => 4, 'stock_uom_id' => 1, 'quantity' => '1.5', 'factor' => '10', 'conversion_snapshot' => []],
            ],
        ];

        $intents = PhysicalSaleStockPostingIntent::build($document);

        $this->assertSame([1, 2], array_column($intents, 'line_number'));
        $this->assertSame('ISSUE', $intents[0]['movement_type']);
        $this->assertSame('OUT', $intents[0]['direction']);
        $this->assertSame(1, $intents[0]['uom_id']);
        $this->assertSame('15.00000000', $intents[0]['quantity']);
        $this->assertSame('POS', $intents[0]['source_type']);
        $this->assertSame($intents[0]['idempotency_key'], PhysicalSaleStockPostingIntent::build($document)[0]['idempotency_key']);
    }

    public function test_requires_persisted_line_identity(): void
    {
        $this->expectException(ValidationException::class);
        PhysicalSaleStockPostingIntent::build([
            'physical_sale_id' => 1, 'document_type' => 'IV', 'source_type' => 'SALES_ORDER', 'source_id' => 1,
            'warehouse_id' => 1, 'business_date' => '2026-08-26', 'document_number' => 'IV-1',
            'lines' => [['line_number' => 1, 'item_id' => 1, 'uom_id' => 1, 'stock_uom_id' => 1, 'quantity' => '1', 'factor' => '1']],
        ]);
    }
}
