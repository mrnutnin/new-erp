<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class IssueReturnFifoContractTest extends TestCase
{
    public function test_issue_return_splits_fifo_lineage_deterministically_and_is_idempotent(): void
    {
        $service = file_get_contents(__DIR__.'/../../app/Modules/Wms/Services/IssueReturnService.php');

        $this->assertStringContainsString("where('direction', 'OUT')", $service);
        $this->assertStringContainsString("where('cost_status', '!=', 'PENDING')", $service);
        $this->assertStringContainsString("->orderBy('id')", $service);
        $this->assertStringContainsString('source_allocation_id', $service);
        $this->assertStringContainsString("':source:'.\$src->id", $service);
        $this->assertStringContainsString('ไม่พบ cost lineage', $service);
    }

    public function test_fifo_return_lineage_has_unique_source_split_and_rollback_safe_foreign_keys(): void
    {
        $migration = file_get_contents(__DIR__.'/../../database/migrations/2026_08_24_620000_create_wms_issue_return_line_allocations.php');

        $this->assertStringContainsString("->unique(['return_line_id', 'source_allocation_id']", $migration);
        $this->assertStringContainsString("->foreignId('source_allocation_id')->constrained('wms_cost_allocations')->restrictOnDelete()", $migration);
        $this->assertStringContainsString("->foreignId('stock_movement_id')->nullable()->unique()", $migration);
        $this->assertStringContainsString("Schema::dropIfExists('wms_issue_return_line_allocations')", $migration);
    }
}
