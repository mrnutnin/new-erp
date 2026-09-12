<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\ManualProductionReceiptPostingContract;
use Brick\Math\BigDecimal;
use Tests\TestCase;

final class ManualProductionReceiptPostingContractTest extends TestCase
{
    public function test_plan_is_deterministic_and_contains_movement_allocation_and_journal_contract(): void
    {
        $plan = ManualProductionReceiptPostingContract::plan([
            'id' => 10,
            'document_context' => 'PRODUCTION_RECEIPT',
            'status' => 'APPROVED',
            'warehouse_id' => 2,
            'document_number' => 'ADJ-202609-000010',
            'document_date' => '2026-09-07',
            'reason' => 'รับสินค้าผลิตเสร็จจาก Manual production',
            'lines' => [['id' => 7, 'item_id' => 11, 'uom_id' => 3, 'quantity' => '3', 'value' => '100']],
        ]);

        self::assertSame('production.finished_receipt', $plan['event_code']);
        self::assertSame('RECEIPT', $plan['movement_intents'][0]['movement_type']);
        self::assertSame('IN', $plan['movement_intents'][0]['direction']);
        self::assertSame('33.33333333', $plan['allocation_intents'][0]['unit_cost']);
        self::assertSame('100.00000000', $plan['movement_intents'][0]['metadata']['receipt_value']);
        self::assertSame(['FINISHED_GOODS', 'WIP', 'PRODUCTION_VARIANCE'], $plan['journal_roles']);
        self::assertNotEmpty($plan['posting_hash']);
        self::assertSame($plan, ManualProductionReceiptPostingContract::plan([
            'id' => 10,
            'document_context' => 'PRODUCTION_RECEIPT',
            'status' => 'APPROVED',
            'warehouse_id' => 2,
            'document_number' => 'ADJ-202609-000010',
            'document_date' => '2026-09-07',
            'reason' => 'รับสินค้าผลิตเสร็จจาก Manual production',
            'lines' => [['id' => 7, 'item_id' => 11, 'uom_id' => 3, 'quantity' => '3', 'value' => '100']],
        ]));
    }

    public function test_rounding_residual_is_assigned_to_the_last_line_without_changing_total(): void
    {
        $plan = ManualProductionReceiptPostingContract::plan([
            'id' => 11, 'document_context' => 'PRODUCTION_RECEIPT', 'status' => 'APPROVED',
            'warehouse_id' => 2, 'document_number' => 'ADJ-202609-000011', 'document_date' => '2026-09-07',
            'reason' => 'รับสินค้าผลิตหลายรายการเพื่อทดสอบ rounding',
            'lines' => [
                ['id' => 8, 'item_id' => 11, 'uom_id' => 3, 'quantity' => '3', 'value' => '100'],
                ['id' => 9, 'item_id' => 12, 'uom_id' => 3, 'quantity' => '3', 'value' => '100'],
            ],
        ]);

        self::assertSame('99.99999999', $plan['allocation_intents'][0]['value']);
        self::assertSame('100.00000001', $plan['allocation_intents'][1]['value']);
        self::assertSame('200.00000000', BigDecimal::of($plan['allocation_intents'][0]['value'])->plus($plan['allocation_intents'][1]['value'])->toScale(8)->__toString());
    }
}
