<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\InventoryReconciliationCalculator;
use PHPUnit\Framework\TestCase;

class InventoryReconciliationCalculatorTest extends TestCase
{
    public function test_it_reports_zero_difference_when_all_sources_match(): void
    {
        $result = InventoryReconciliationCalculator::totals('100.25', '100.25', '100.25');

        $this->assertSame('100.25000000', $result['allocation_value']);
        $this->assertSame('0.00000000', $result['allocation_vs_gl_difference']);
        $this->assertSame('ตรงกัน', $result['status']);
    }

    public function test_unlinked_allocations_require_review_even_when_values_match(): void
    {
        $result = InventoryReconciliationCalculator::totals('10', '10', '10', 1);

        $this->assertSame('ต้องตรวจสอบ', $result['status']);
        $this->assertSame(1, $result['unlinked_allocations']);
    }

    public function test_balance_drift_requires_review_even_when_gl_matches(): void
    {
        $result = InventoryReconciliationCalculator::totals('100', '99', '100');

        $this->assertSame('-1.00000000', $result['balance_vs_allocation_difference']);
        $this->assertSame('0.00000000', $result['allocation_vs_gl_difference']);
        $this->assertSame('ต้องตรวจสอบ', $result['status']);
    }

    public function test_gl_difference_requires_review_even_when_stock_projection_matches(): void
    {
        $result = InventoryReconciliationCalculator::totals('100', '100', '99.99');

        $this->assertSame('0.01000000', $result['allocation_vs_gl_difference']);
        $this->assertSame('ต้องตรวจสอบ', $result['status']);
    }

    public function test_gl_comparison_uses_per_allocation_accounting_precision(): void
    {
        $result = InventoryReconciliationCalculator::totals('0.025', '0.025', '0.03', 0, 0, '0', 0, 0, '0', '0.03');

        $this->assertSame('0.02500000', $result['allocation_value']);
        $this->assertSame('0.03000000', $result['allocation_gl_value']);
        $this->assertSame('0.00000000', $result['allocation_vs_gl_difference']);
    }
}
