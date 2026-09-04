<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Services\InventoryReconciliationService;
use App\Modules\Wms\Services\OpeningBalanceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Rollback-only dedicated MySQL coverage for the Opening Balance boundary. */
final class OpeningBalanceMySqlIntegrationReadinessTest extends TestCase
{
    public function test_opening_balance_posts_an_idempotent_stock_cost_chain(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $actor = User::query()->first();
        $warehouse = Warehouse::query()->where('is_active', true)->first();
        $item = Item::query()->where('is_active', true)->where('is_stock_item', true)->first();
        if (! $actor || ! $warehouse || ! $item || ! $item->base_uom_id) {
            $this->markTestSkipped('ต้องมี User/Warehouse/Stock Item fixture ใน local MySQL');
        }

        $method = strtoupper((string) app(\App\Modules\Settings\Services\GlobalSettings::class)->value('inventory_costing_method'));
        if (! in_array($method, ['AVG', 'FIFO'], true)) {
            $this->markTestSkipped('ต้องตั้ง Global inventory costing method เป็น AVG หรือ FIFO');
        }

        $before = [
            'batches' => DB::table('wms_opening_balance_batches')->count(),
            'movements' => DB::table('wms_stock_movements')->count(),
            'layers' => DB::table('wms_stock_cost_layers')->count(),
            'allocations' => DB::table('wms_cost_allocations')->count(),
        ];
        $beforeOnHand = (float) (DB::table('wms_stock_balances')->where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->value('on_hand') ?? 0);
        DB::beginTransaction();
        try {
            $firstMovementDate = DB::table('wms_stock_movements')
                ->where('warehouse_id', $warehouse->id)
                ->where('item_id', $item->id)
                ->where('status', 'POSTED')
                ->min('business_date');
            $date = $firstMovementDate
                ? \Illuminate\Support\Carbon::parse($firstMovementDate)->subDays(1000)->format('Y-m-d')
                : now()->format('Y-m-d');
            $key = 'opening-e2e-'.str()->uuid();
            $batch = app(OpeningBalanceService::class)->createDraft([
                'warehouse_id' => $warehouse->id,
                'cutover_date' => $date,
                'costing_method' => $method,
                'source_reference' => 'E2E-OPENING',
                'idempotency_key' => $key,
                'lines' => [[
                    'item_id' => $item->id,
                    'uom_id' => $item->base_uom_id,
                    'quantity' => '12',
                    'total_value' => '1200',
                ]],
            ], $actor);
            $this->assertSame('DRAFT', $batch->status);
            $posted = app(OpeningBalanceService::class)->post($batch, $actor);
            $this->assertSame('POSTED', $posted->status);
            $line = $posted->lines->sole();
            $this->assertNotNull($line->stock_movement_id);
            $this->assertNotNull($line->cost_layer_id);
            $this->assertSame(1, DB::table('wms_cost_allocations')->where('stock_movement_id', $line->stock_movement_id)->count());
            $this->assertSame(12.0, (float) DB::table('wms_stock_balances')->where('warehouse_id', $warehouse->id)->where('item_id', $item->id)->value('on_hand') - $beforeOnHand);
            $this->assertSame('ต้องตรวจสอบ', app(InventoryReconciliationService::class)->totals($date, $warehouse->id, $item->id)['status']);
            try {
                app(OpeningBalanceService::class)->post($posted, $actor);
                $this->fail('Expected a posted Opening Balance to be immutable.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('status', $exception->errors());
            }
        } finally {
            DB::rollBack();
        }

        $this->assertSame($before, [
            'batches' => DB::table('wms_opening_balance_batches')->count(),
            'movements' => DB::table('wms_stock_movements')->count(),
            'layers' => DB::table('wms_stock_cost_layers')->count(),
            'allocations' => DB::table('wms_cost_allocations')->count(),
        ]);
    }
}
