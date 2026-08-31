<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\PhysicalSalePostingExecutionPlan;
use Tests\TestCase;

class PhysicalSalePostingExecutionPlanTest extends TestCase
{
    public function test_execution_plan_has_explicit_transaction_stage_order(): void
    {
        $plan = PhysicalSalePostingExecutionPlan::build($this->sale());

        $this->assertSame(PhysicalSalePostingExecutionPlan::stageOrder(), array_column($plan['stages'], 'name'));
        $this->assertSame(['source_lock'], $plan['stages'][1]['depends_on']);
        $this->assertSame(['cogs_journal'], $plan['stages'][4]['depends_on']);
        $this->assertSame(['cogs_journal', 'revenue_journal'], $plan['stages'][5]['depends_on']);
        $this->assertSame('POSTED', $plan['stages'][5]['payload']['status_after_success']);
    }

    public function test_execution_plan_reuses_readiness_and_posting_identity(): void
    {
        $plan = PhysicalSalePostingExecutionPlan::build($this->sale());

        $this->assertSame(42, $plan['readiness']['sale_id']);
        $this->assertSame($plan['posting_plan']['identity_key'], $plan['identity_key']);
        $this->assertSame('HS-000042', $plan['stages'][3]['payload']['source_reference']);
        $this->assertSame(7, $plan['stages'][0]['payload']['source_id']);
        $this->assertCount(2, $plan['stages'][1]['payload']['stock_intents']);
    }

    private function sale(): array
    {
        return [
            'id' => 42,
            'physical_sale_id' => 42,
            'status' => 'DRAFT',
            'document_type' => 'HS',
            'document_number' => 'HS-000042',
            'source_type' => 'SALES_ORDER',
            'source_id' => 7,
            'warehouse_id' => 3,
            'party_id' => 9,
            'ar_account_id' => 1100,
            'document_date' => '2026-08-27',
            'posting_date' => '2026-08-27',
            'business_date' => '2026-08-27',
            'tax_amount' => '0.00',
            'total_amount' => '250.00',
            'lines' => [
                ['line_id' => 101, 'line_number' => 1, 'item_id' => 11, 'uom_id' => 1, 'sale_uom_id' => 1, 'stock_uom_id' => 1, 'warehouse_id' => 3, 'quantity' => '2', 'stock_quantity' => '2', 'factor' => '1', 'line_total' => '100.00', 'revenue_account_id' => 4100, 'business_date' => '2026-08-27'],
                ['line_id' => 102, 'line_number' => 2, 'item_id' => 12, 'uom_id' => 1, 'sale_uom_id' => 1, 'stock_uom_id' => 1, 'warehouse_id' => 3, 'quantity' => '3', 'stock_quantity' => '3', 'factor' => '1', 'line_total' => '150.00', 'revenue_account_id' => 4100, 'business_date' => '2026-08-27'],
            ],
        ];
    }
}
