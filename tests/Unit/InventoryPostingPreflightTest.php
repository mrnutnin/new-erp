<?php

namespace Tests\Unit;

use App\Modules\Wms\Support\InventoryPostingPreflight;
use PHPUnit\Framework\TestCase;

class InventoryPostingPreflightTest extends TestCase
{
    public function test_posting_is_blocked_by_pending_or_unlinked_allocations(): void
    {
        $result = InventoryPostingPreflight::evaluate([
            'movement_status' => 'POSTED', 'direction' => 'OUT', 'inventory_account_ready' => true,
            'cogs_account_ready' => true, 'allocation_count' => 1, 'pending_count' => 1, 'unlinked_count' => 1,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertSame(['no_pending_cost', 'all_allocations_linked', 'line_proof_ready'], $result['blockers']);
    }

    public function test_receipt_with_final_linked_allocation_is_ready(): void
    {
        $result = InventoryPostingPreflight::evaluate([
            'movement_status' => 'POSTED', 'direction' => 'IN', 'inventory_account_ready' => true,
            'cogs_account_ready' => false, 'allocation_count' => 1, 'pending_count' => 0, 'unlinked_count' => 0, 'line_proof_ready' => true,
        ]);

        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_issue_requires_cogs_and_posted_movement(): void
    {
        $result = InventoryPostingPreflight::evaluate([
            'movement_status' => 'DRAFT', 'direction' => 'OUT', 'inventory_account_ready' => true,
            'cogs_account_ready' => false, 'allocation_count' => 1, 'pending_count' => 0, 'unlinked_count' => 0, 'line_proof_ready' => true,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertSame(['movement_posted', 'cogs_account_ready'], $result['blockers']);
    }

    public function test_source_identity_is_required_when_the_gate_is_requested(): void
    {
        $result = InventoryPostingPreflight::evaluate([
            'movement_status' => 'POSTED', 'direction' => 'IN', 'inventory_account_ready' => true,
            'cogs_account_ready' => false, 'allocation_count' => 1, 'pending_count' => 0,
            'unlinked_count' => 0, 'line_proof_ready' => true, 'source_ready' => false,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertSame(['source_ready'], $result['blockers']);
    }

    public function test_source_identity_can_clear_the_gate(): void
    {
        $result = InventoryPostingPreflight::evaluate([
            'movement_status' => 'POSTED', 'direction' => 'IN', 'inventory_account_ready' => true,
            'cogs_account_ready' => false, 'allocation_count' => 1, 'pending_count' => 0,
            'unlinked_count' => 0, 'line_proof_ready' => true, 'source_ready' => true,
        ]);

        $this->assertTrue($result['ready']);
        $this->assertSame([], $result['blockers']);
    }
}
