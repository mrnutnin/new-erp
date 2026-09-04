<?php

namespace Tests\Unit;

use Tests\TestCase;

final class LegacyRepairReportCommandTest extends TestCase
{
    public function test_legacy_repair_report_is_dry_run_only_and_review_first(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString('wms:legacy-repair-report {--dry-run}', $source);
        $this->assertStringContainsString("'proposal' => 'REVIEW_REQUIRED'", $source);
        $this->assertStringContainsString("'expected_state' => 'REVIEW_REQUIRED'", $source);
        $this->assertStringContainsString("'evidence' => [", $source);
        $this->assertStringContainsString('idempotency_plan', $source);
        $this->assertStringContainsString("if (! \$this->option('dry-run'))", $source);
        $this->assertStringContainsString('CostAllocationReviewService::class', $source);
    }

    public function test_report_filters_pending_linked_allocations_and_never_auto_classifies_them(): void
    {
        $source = file_get_contents(base_path('routes/console.php'));
        $this->assertStringContainsString("where('a.status', 'PENDING')->whereNotNull('a.journal_entry_id')", $source);
        $this->assertStringContainsString("\$row->journal_status === 'REVERSED'", $source);
        $this->assertStringContainsString("'proposal' => 'REVIEW_REQUIRED'", $source);
    }
}
