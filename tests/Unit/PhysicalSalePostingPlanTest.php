<?php

namespace Tests\Unit;

use App\Modules\Pos\Support\PhysicalSalePostingPlan;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PhysicalSalePostingPlanTest extends TestCase
{
    public function test_plan_keeps_stock_and_revenue_identity_aligned(): void
    {
        $plan = PhysicalSalePostingPlan::build($this->sale());

        $this->assertSame(64, strlen($plan['identity_key']));
        $this->assertCount(2, $plan['stock_intents']);
        $this->assertSame('42', $plan['revenue_journal']['source_id']);
        $this->assertSame('HS-000042', $plan['revenue_journal']['source_reference']);
    }

    public function test_plan_rejects_missing_stock_line(): void
    {
        $sale = $this->sale();
        $sale['lines'][1]['line_id'] = null;

        $this->expectException(ValidationException::class);
        PhysicalSalePostingPlan::build($sale);
    }

    public function test_build_ready_applies_readiness_gate_before_building_plan(): void
    {
        $sale = $this->sale();
        $sale['status'] = 'DRAFT';
        $sale['document_type'] = 'IV';
        $sale['posting_date'] = '2026-08-27';
        $sale['total_amount'] = '250.00';

        $plan = PhysicalSalePostingPlan::buildReady($sale);

        $this->assertSame(42, $plan['readiness']['sale_id']);
        $this->assertSame(2, $plan['readiness']['line_count']);
        $this->assertSame('250.00', $plan['readiness']['total_amount']);
        $this->assertCount(2, $plan['stock_intents']);
    }

    private function sale(): array
    {
        return [
            'id' => 42,
            'physical_sale_id' => 42,
            'document_type' => 'HS',
            'document_number' => 'HS-000042',
            'source_type' => 'SALES_ORDER',
            'source_id' => 7,
            'warehouse_id' => 3,
            'party_id' => 9,
            'ar_account_id' => 1100,
            'document_date' => '2026-08-27',
            'business_date' => '2026-08-27',
            'tax_amount' => '0.00',
            'lines' => [
                ['line_id' => 101, 'line_number' => 1, 'item_id' => 11, 'uom_id' => 1, 'sale_uom_id' => 1, 'stock_uom_id' => 1, 'warehouse_id' => 3, 'quantity' => '2', 'stock_quantity' => '2', 'factor' => '1', 'line_total' => '100.00', 'revenue_account_id' => 4100, 'business_date' => '2026-08-27'],
                ['line_id' => 102, 'line_number' => 2, 'item_id' => 12, 'uom_id' => 1, 'sale_uom_id' => 1, 'stock_uom_id' => 1, 'warehouse_id' => 3, 'quantity' => '3', 'stock_quantity' => '3', 'factor' => '1', 'line_total' => '150.00', 'revenue_account_id' => 4100, 'business_date' => '2026-08-27'],
            ],
        ];
    }
}
