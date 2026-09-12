<?php

namespace Tests\Feature;

use App\Modules\Wms\Services\CostShadowCalculationService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class CostShadowFifoMySqlIntegrationReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
    }

    public function test_fifo_receipt_reaches_its_exact_layer_consumption_without_writes(): void
    {
        $root = DB::table('wms_cost_allocations as root')
            ->join('wms_cost_allocations as child', 'child.stock_cost_layer_id', '=', 'root.stock_cost_layer_id')
            ->where('root.method', 'FIFO')->where('root.direction', 'IN')->where('child.direction', 'OUT')
            ->whereColumn('child.business_date', '>=', 'root.business_date')
            ->where('root.status', '!=', 'REVERSED')->where('child.status', '!=', 'REVERSED')
            ->orderByDesc('root.id')
            ->first(['root.id', 'root.stock_cost_layer_id', 'root.quantity', 'root.unit_cost', 'child.id as child_id']);
        if (! $root) {
            $this->markTestSkipped('ยังไม่มี FIFO receipt → consumption fixture ในฐานข้อมูลนี้');
        }

        $before = $this->ledgerCounts();
        $proposed = BigDecimal::of((string) $root->unit_cost)->plus('1')->__toString();
        $result = app(CostShadowCalculationService::class)->calculate((int) $root->id, $proposed);
        $rows = collect($result['rows']);

        self::assertTrue($result['read_only']);
        self::assertSame('cost-shadow-v2-fifo', $result['calculation_contract_version']);
        self::assertSame((int) $root->stock_cost_layer_id, $rows->firstWhere('allocation_id', (int) $root->id)['stock_cost_layer_id']);
        self::assertSame((int) $root->stock_cost_layer_id, $rows->firstWhere('allocation_id', (int) $root->child_id)['stock_cost_layer_id']);
        self::assertSame(
            BigDecimal::of((string) $root->quantity)->toScale(8)->__toString(),
            $rows->firstWhere('allocation_id', (int) $root->id)['estimated_delta_value'],
        );
        self::assertNotSame('0.00000000', $rows->firstWhere('allocation_id', (int) $root->child_id)['estimated_delta_value']);
        self::assertSame('0.00000000', $result['impact_summary']['rounding_residual_value']);
        self::assertSame($before, $this->ledgerCounts());
    }

    /** @return array<string, int> */
    private function ledgerCounts(): array
    {
        return collect(['wms_stock_movements', 'wms_cost_allocations', 'wms_cost_revaluation_runs', 'wms_cost_revaluation_deltas'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
