<?php

namespace Tests\Feature;

use App\Modules\Wms\Services\CostTimelineReader;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CostTimelineReaderMySqlIntegrationReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
    }

    public function test_partition_is_read_with_stable_keyset_order_and_without_writes(): void
    {
        $scope = DB::table('wms_cost_allocations')->where('status', '!=', 'REVERSED')
            ->whereIn('method', ['AVG', 'FIFO'])
            ->selectRaw('warehouse_id, item_id, uom_id, method, MIN(business_date) AS impact_start_date, COUNT(*) AS rows_count')
            ->groupBy('warehouse_id', 'item_id', 'uom_id', 'method')->havingRaw('COUNT(*) > 1')
            ->orderByDesc('rows_count')->first();
        $this->assertNotNull($scope, 'ต้องมี cost partition อย่างน้อยสอง allocations สำหรับทดสอบ keyset');
        $explain = DB::selectOne(
            'EXPLAIN SELECT id, stock_movement_id, business_date FROM wms_cost_allocations WHERE warehouse_id = ? AND item_id = ? AND uom_id = ? AND method = ? AND business_date >= ? AND status <> ? ORDER BY business_date, stock_movement_id, id LIMIT 251',
            [$scope->warehouse_id, $scope->item_id, $scope->uom_id, $scope->method, $scope->impact_start_date, 'REVERSED'],
        );
        $this->assertSame('wms_ca_timeline_partition_idx', $explain->key);
        $this->assertStringNotContainsString('Using filesort', (string) $explain->Extra);
        $before = $this->ledgerCounts();
        $reader = app(CostTimelineReader::class);

        $first = $reader->read((int) $scope->warehouse_id, (int) $scope->item_id, (int) $scope->uom_id, $scope->method, $scope->impact_start_date, limit: 1);

        $this->assertTrue($first['read_only']);
        $this->assertCount(1, $first['rows']);
        $this->assertTrue($first['page']['has_more']);
        $this->assertNotNull($first['page']['next_cursor']);
        $second = $reader->read((int) $scope->warehouse_id, (int) $scope->item_id, (int) $scope->uom_id, $scope->method, $scope->impact_start_date, $first['page']['next_cursor'], 1);

        $this->assertNotSame($first['rows'][0]['allocation_id'], $second['rows'][0]['allocation_id']);
        $this->assertLessThan(0, $this->compare($first['rows'][0]['cursor'], $second['rows'][0]['cursor']));

        $bounded = $reader->read((int) $scope->warehouse_id, (int) $scope->item_id, (int) $scope->uom_id, $scope->method, '2099-12-31', anchorLimit: 1);
        $this->assertSame('REQUIRES_REBUILD', $bounded['anchor']['status']);
        $this->assertContains('ANCHOR_LIMIT_EXCEEDED', $bounded['blockers']);
        $this->assertSame($before, $this->ledgerCounts());
    }

    /** @param array<string, mixed> $left @param array<string, mixed> $right */
    private function compare(array $left, array $right): int
    {
        return [$left['business_date'], $left['movement_id'], $left['allocation_id']]
            <=> [$right['business_date'], $right['movement_id'], $right['allocation_id']];
    }

    /** @return array<string, int> */
    private function ledgerCounts(): array
    {
        return collect(['wms_stock_movements', 'wms_cost_allocations', 'wms_cost_revaluation_runs', 'wms_cost_revaluation_deltas'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
