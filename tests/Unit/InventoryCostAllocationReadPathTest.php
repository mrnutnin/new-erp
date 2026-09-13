<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\InventoryCostAllocationService;
use Illuminate\Database\Eloquent\Builder;
use Tests\TestCase;

class InventoryCostAllocationReadPathTest extends TestCase
{
    public function test_valuation_query_is_grouped_and_does_not_execute_until_consumed(): void
    {
        $query = (new InventoryCostAllocationService)->valuationQuery('2026-08-21', 7, 11);

        $this->assertInstanceOf(Builder::class, $query);
        $this->assertStringContainsString('group by', strtolower($query->toSql()));
        $this->assertContains('2026-08-21', $query->getBindings());
        $this->assertContains('REVERSED', $query->getBindings());
        $this->assertContains('RECOST', $query->getBindings());
        $this->assertContains(7, $query->getBindings());
        $this->assertContains(11, $query->getBindings());
    }

    public function test_historical_query_excludes_pending_value_from_final_and_is_paginated_compatible(): void
    {
        $query = (new InventoryCostAllocationService)->historicalValuationQuery('2026-08-21', 7, 11);
        $sql = strtolower($query->toSql());

        $this->assertInstanceOf(Builder::class, $query);
        $this->assertStringContainsString('pending', $sql);
        $this->assertStringContainsString('group by', $sql);
        $this->assertContains('2026-08-21', $query->getBindings());
        $this->assertContains('REVERSED', $query->getBindings());
        $this->assertContains(7, $query->getBindings());
        $this->assertContains(11, $query->getBindings());
    }
}
