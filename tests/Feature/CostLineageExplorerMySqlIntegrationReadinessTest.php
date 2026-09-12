<?php

namespace Tests\Feature;

use App\Modules\Wms\Services\CostLineageExplorer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CostLineageExplorerMySqlIntegrationReadinessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
    }

    public function test_read_only_snapshot_reports_real_allocation_lineage_without_writes(): void
    {
        $warehouseId = (int) DB::table('warehouses')->whereNull('deleted_at')->where('is_active', true)->orderBy('id')->value('id');
        $this->assertGreaterThan(0, $warehouseId);

        $beforeAllocations = DB::table('wms_cost_allocations')->count();
        $beforeMovements = DB::table('wms_stock_movements')->count();
        $snapshot = app(CostLineageExplorer::class)->snapshot($warehouseId);

        $this->assertArrayHasKey('summary', $snapshot);
        $this->assertArrayHasKey('rows', $snapshot);
        $this->assertArrayHasKey('edges', $snapshot);
        $this->assertIsInt($snapshot['summary']['nodes']);
        $this->assertIsInt($snapshot['summary']['edges']);
        $this->assertGreaterThanOrEqual(0, $snapshot['summary']['missing_parents']);
        $this->assertGreaterThanOrEqual(0, $snapshot['summary']['missing_movements']);
        $this->assertGreaterThanOrEqual(0, $snapshot['summary']['cycles']);
        $this->assertSame($beforeAllocations, DB::table('wms_cost_allocations')->count());
        $this->assertSame($beforeMovements, DB::table('wms_stock_movements')->count());
    }
}
