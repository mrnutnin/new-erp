<?php

namespace Tests\Unit;

use Tests\TestCase;

final class CostAllocationReviewServiceTest extends TestCase
{
    public function test_review_service_requires_confirmation_and_evidence_without_mutating_allocation(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/CostAllocationReviewService.php'));
        $this->assertStringContainsString("'proposed_state' => 'REVIEW_REQUIRED'", $source);
        $this->assertStringContainsString('DB::transaction', $source);
        $this->assertStringContainsString('hash_equals', $source);
        $this->assertStringContainsString('wms.cost_allocation.reviewed', $source);
        $this->assertStringNotContainsString('->save()', $source);
        $this->assertStringNotContainsString('->update(', $source);
    }
}
