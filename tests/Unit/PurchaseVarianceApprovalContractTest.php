<?php

namespace Tests\Unit;

use App\Modules\Wms\Models\PurchaseVarianceApproval;
use App\Modules\Wms\Support\PurchaseThreeWayMatchPolicy;
use Tests\TestCase;

final class PurchaseVarianceApprovalContractTest extends TestCase
{
    public function test_evidence_hash_is_stable_for_the_same_match_and_policy(): void
    {
        $policy = new PurchaseThreeWayMatchPolicy(requireApprovalOnVariance: true, blockOnVariance: false);
        $match = ['blockers' => ['invoice_price_variance'], 'lines' => [['price_variance' => '10.00000000']]];

        $this->assertSame(
            PurchaseVarianceApproval::evidenceHash($match, $policy),
            PurchaseVarianceApproval::evidenceHash($match, $policy),
        );
    }

    public function test_evidence_hash_changes_when_source_snapshot_or_policy_changes(): void
    {
        $policy = new PurchaseThreeWayMatchPolicy(requireApprovalOnVariance: true, blockOnVariance: false);
        $match = ['blockers' => ['invoice_price_variance'], 'lines' => [['price_variance' => '10.00000000']]];
        $changedMatch = ['blockers' => ['invoice_price_variance'], 'lines' => [['price_variance' => '11.00000000']]];
        $blockingPolicy = new PurchaseThreeWayMatchPolicy(requireApprovalOnVariance: true, blockOnVariance: true);

        $this->assertNotSame(PurchaseVarianceApproval::evidenceHash($match, $policy), PurchaseVarianceApproval::evidenceHash($changedMatch, $policy));
        $this->assertNotSame(PurchaseVarianceApproval::evidenceHash($match, $policy), PurchaseVarianceApproval::evidenceHash($match, $blockingPolicy));
    }
}
