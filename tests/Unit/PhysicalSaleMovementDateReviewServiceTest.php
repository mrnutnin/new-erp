<?php

namespace Tests\Unit;

use Tests\TestCase;

final class PhysicalSaleMovementDateReviewServiceTest extends TestCase
{
    public function test_legacy_pos_date_recovery_is_quarantined_without_mutating_immutable_rows(): void
    {
        $source = file_get_contents(base_path('app/Modules/Pos/Services/PhysicalSaleMovementDateReviewService.php'));

        $this->assertStringContainsString("'source_type', 'POS'", $source);
        $this->assertStringContainsString("'contract' => 'pos-movement-date-review-v1'", $source);
        $this->assertStringContainsString('CostAllocationReviewService', $source);
        $this->assertStringNotContainsString('->save()', $source);
        $this->assertStringNotContainsString('->update(', $source);
    }
}
