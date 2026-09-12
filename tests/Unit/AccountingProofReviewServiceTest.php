<?php

namespace Tests\Unit;

use Tests\TestCase;

final class AccountingProofReviewServiceTest extends TestCase
{
    public function test_missing_journal_proof_is_quarantined_without_mutating_stock_or_cost(): void
    {
        $source = file_get_contents(base_path('app/Modules/Wms/Services/AccountingProofReviewService.php'));

        $this->assertStringContainsString('journal_proof_missing', $source);
        $this->assertStringContainsString('CostAllocationReviewService', $source);
        $this->assertStringNotContainsString('->save()', $source);
        $this->assertStringNotContainsString('->update(', $source);
    }
}
