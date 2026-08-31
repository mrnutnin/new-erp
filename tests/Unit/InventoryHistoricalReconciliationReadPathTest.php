<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\InventoryReconciliationService;
use Illuminate\Database\Query\Builder;
use Tests\TestCase;

class InventoryHistoricalReconciliationReadPathTest extends TestCase
{
    public function test_historical_reconciliation_is_grouped_and_read_only(): void
    {
        $query = app(InventoryReconciliationService::class)->historicalQuery('2026-08-21', 7, 11);
        $sql = strtolower($query->toSql());

        $this->assertInstanceOf(Builder::class, $query);
        $this->assertStringContainsString('wms_cost_allocations', $sql);
        $this->assertStringContainsString('journal_entry_lines', $sql);
        $this->assertStringContainsString('group by', $sql);
    }
}
