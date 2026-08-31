<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\InventoryPostingPreflightReader;
use App\Modules\Wms\Services\InventoryWarehouseReleaseGate;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

final class InventoryWarehouseReleaseGateTest extends TestCase
{
    public function test_gate_is_warehouse_scoped_and_does_not_require_global_legacy_reviews_to_be_zero(): void
    {
        $preflight = Mockery::mock(InventoryPostingPreflightReader::class);
        $preflight->expects('summary')->with(7)->andReturn([
            'warehouse_id' => 7,
            'posting_enabled' => false,
            'global_unresolved_legacy_review' => 2,
            'unresolved_legacy_review' => 0,
            'pending' => 0,
            'unlinked' => 0,
            'missingInventory' => 0,
            'missingCogs' => 0,
            'missingSource' => 0,
            'lineUnlinked' => 0,
            'lineMismatched' => 0,
            'lineProofMissing' => 0,
            'reconciliation_ready' => true,
            'reconciliation_blockers' => [],
        ]);

        $result = (new InventoryWarehouseReleaseGate($preflight))->inspect(7);

        $this->assertTrue($result['ready']);
        $this->assertSame('WAREHOUSE_ONLY', $result['release_scope']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_unresolved_review_and_incomplete_linkage_block_the_warehouse(): void
    {
        $preflight = Mockery::mock(InventoryPostingPreflightReader::class);
        $preflight->expects('summary')->with(8)->andReturn([
            'warehouse_id' => 8,
            'posting_enabled' => true,
            'unresolvedLegacyReview' => 1,
            'pending' => 1,
            'unlinked' => 1,
            'missingInventory' => 0,
            'missingCogs' => 0,
            'missingSource' => 0,
            'lineUnlinked' => 0,
            'lineMismatched' => 1,
            'lineProofMissing' => 0,
            'reconciliation_ready' => false,
            'reconciliation_blockers' => ['no_unresolved_legacy_review'],
        ]);

        $result = (new InventoryWarehouseReleaseGate($preflight))->inspect(8);

        $this->assertFalse($result['ready']);
        $this->assertContains('unresolved_legacy_review', $result['blockers']);
        $this->assertContains('pending_cost_allocations', $result['blockers']);
        $this->assertContains('mismatched_journal_line_proof', $result['blockers']);
        $this->assertContains('no_unresolved_legacy_review', $result['blockers']);
    }

    public function test_assert_posting_allowed_never_opens_a_closed_global_flag(): void
    {
        $this->app['config']->set('erp.inventory.purchase_posting_enabled', false);
        $preflight = Mockery::mock(InventoryPostingPreflightReader::class);
        $preflight->expects('summary')->with(9)->andReturn([
            'warehouse_id' => 9,
            'posting_enabled' => false,
            'unresolved_legacy_review' => 0,
            'pending' => 0,
            'unlinked' => 0,
            'missingInventory' => 0,
            'missingCogs' => 0,
            'missingSource' => 0,
            'lineUnlinked' => 0,
            'lineMismatched' => 0,
            'lineProofMissing' => 0,
            'reconciliation_ready' => true,
            'reconciliation_blockers' => [],
        ]);

        $this->expectException(ValidationException::class);
        try {
            (new InventoryWarehouseReleaseGate($preflight))->assertPostingAllowed(9);
        } finally {
            $this->assertFalse((bool) config('erp.inventory.purchase_posting_enabled'));
        }
    }
}
