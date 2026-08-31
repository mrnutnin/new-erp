<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\InventoryReconciliationGate;
use PHPUnit\Framework\TestCase;

class InventoryReconciliationGateTest extends TestCase
{
    public function test_reconciliation_passes_only_when_all_proofs_match(): void
    {
        $result = InventoryReconciliationGate::evaluate([
            'allocation_vs_gl_difference' => '0.00000000',
            'balance_vs_allocation_difference' => '0',
            'unlinked_allocations' => 0,
        ]);

        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_difference_or_unlinked_allocation_blocks_release(): void
    {
        $result = InventoryReconciliationGate::evaluate([
            'allocation_vs_gl_difference' => '0.01',
            'balance_vs_allocation_difference' => '-2',
            'unlinked_allocations' => 1,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertSame([
            'allocation_vs_gl_zero',
            'balance_vs_allocation_zero',
            'no_unlinked_allocations',
        ], $result['blockers']);
    }

    public function test_unresolved_legacy_review_rows_block_even_when_totals_are_zero(): void
    {
        $result = InventoryReconciliationGate::evaluate([
            'allocation_vs_gl_difference' => '0',
            'balance_vs_allocation_difference' => '0',
            'unlinked_allocations' => 0,
            'unresolved_legacy_review' => 2,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertSame(['no_unresolved_legacy_review'], $result['blockers']);
    }
}
