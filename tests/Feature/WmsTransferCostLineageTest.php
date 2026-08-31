<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\Transfer;
use App\Modules\Wms\Services\TransferMovementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WmsTransferCostLineageTest extends TestCase
{
    use DatabaseTransactions;

    private object $sourceWarehouse;

    private object $destinationWarehouse;

    private object $thirdWarehouse;

    private object $item;

    private object $uom;

    private User $actor;

    private string $date;

    private array $settingsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        // This suite exercises the real, seeded ERP schema and is intentionally
        // opt-in. The default PHPUnit harness uses an empty SQLite :memory:
        // database, so attempting to query users/warehouses here is a harness
        // error rather than a transfer-domain failure.
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $this->actor = User::query()->firstOrFail();
        $this->sourceWarehouse = DB::table('warehouses')->whereNull('deleted_at')->orderBy('id')->firstOrFail();
        $branchId = (int) $this->sourceWarehouse->branch_id;
        $stamp = substr((string) hrtime(true), -10);
        $this->destinationWarehouse = (object) ['id' => DB::table('warehouses')->insertGetId(['branch_id' => $branchId, 'code' => "IT-B-{$stamp}", 'name' => 'Transfer Test B', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()])];
        $this->thirdWarehouse = (object) ['id' => DB::table('warehouses')->insertGetId(['branch_id' => $branchId, 'code' => "IT-C-{$stamp}", 'name' => 'Transfer Test C', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()])];
        $this->item = DB::table('wms_items')->where('is_active', true)->orderBy('id')->firstOrFail();
        $this->uom = DB::table('wms_uoms')->where('is_active', true)->orderBy('id')->firstOrFail();
        $period = DB::table('fiscal_periods')->where('status', 'OPEN')->orderBy('start_date')->firstOrFail();
        $this->date = $period->start_date;
        $this->settingsBefore = (array) DB::table('company_settings')->where('id', 1)->firstOrFail();
    }

    /** @return array<string, array{0: string}> */
    public static function costingMethods(): array
    {
        return ['fifo' => ['FIFO'], 'avg' => ['AVG']];
    }

    #[DataProvider('costingMethods')]
    public function test_transfer_chain_preserves_lineage_without_gl(string $method): void
    {
        $this->configureCosting($method);
        $this->seedSourceStock($method, "chain-{$method}");
        $beforeJournals = DB::table('journal_entries')->count();
        $service = app(TransferMovementService::class);
        $first = $service->dispatch($this->draft($this->sourceWarehouse->id, $this->destinationWarehouse->id, "chain-{$method}-ab"), $this->sourceWarehouse->id, $this->actor, 'ส่งไป B');
        $line = $first->lines->first();
        $first = $service->accept($first, $this->destinationWarehouse->id, $this->actor, [$line->id => '10'], "accept-{$method}-ab");
        $second = $service->dispatch($this->draft($this->destinationWarehouse->id, $this->thirdWarehouse->id, "chain-{$method}-bc"), $this->destinationWarehouse->id, $this->actor, 'ส่งไป C');
        $second = $service->accept($second, $this->thirdWarehouse->id, $this->actor, [$second->lines->first()->id => '10'], "accept-{$method}-bc");

        $this->assertSame('ACCEPTED', $second->status);
        $this->assertSame(0.0, (float) DB::table('wms_stock_balances')->where('warehouse_id', $this->sourceWarehouse->id)->where('item_id', $this->item->id)->value('on_hand'));
        $this->assertSame(10.0, (float) DB::table('wms_stock_balances')->where('warehouse_id', $this->thirdWarehouse->id)->where('item_id', $this->item->id)->value('on_hand'));
        $this->assertGreaterThan(0, DB::table('wms_stock_cost_layers')->where('warehouse_id', $this->thirdWarehouse->id)->whereNotNull('parent_layer_id')->count());
        $this->assertSame($beforeJournals, DB::table('journal_entries')->count());
    }

    public function test_partial_accept_reject_and_retry_are_idempotent(): void
    {
        $this->configureCosting('AVG');
        $this->seedSourceStock('AVG', 'partial');
        $service = app(TransferMovementService::class);
        $transfer = $service->dispatch($this->draft($this->sourceWarehouse->id, $this->destinationWarehouse->id, 'partial'), $this->sourceWarehouse->id, $this->actor, 'ส่งบางส่วน');
        $lineId = $transfer->lines->first()->id;
        $transfer = $service->accept($transfer, $this->destinationWarehouse->id, $this->actor, [$lineId => '4'], 'accept-4');
        $movementCount = DB::table('wms_stock_movements')->count();
        $transfer = $service->accept($transfer, $this->destinationWarehouse->id, $this->actor, [$lineId => '4'], 'accept-4');
        $this->assertSame($movementCount, DB::table('wms_stock_movements')->count());
        $transfer = $service->reject($transfer, $this->destinationWarehouse->id, $this->actor, [$lineId => '6'], 'reject-6', 'สินค้าบางส่วนไม่ผ่านตรวจ');
        $this->assertSame('ACCEPTED', $transfer->status);
        $this->assertSame(4.0, (float) DB::table('wms_stock_balances')->where('warehouse_id', $this->destinationWarehouse->id)->where('item_id', $this->item->id)->value('on_hand'));
        $this->assertSame(6.0, (float) DB::table('wms_stock_balances')->where('warehouse_id', $this->sourceWarehouse->id)->where('item_id', $this->item->id)->value('on_hand'));
    }

    public function test_scope_closed_period_and_insufficient_stock_are_blocked(): void
    {
        $this->configureCosting('FIFO');
        $this->seedSourceStock('FIFO', 'guards');
        $service = app(TransferMovementService::class);
        $transfer = $this->draft($this->sourceWarehouse->id, $this->destinationWarehouse->id, 'scope');
        $this->expectException(ValidationException::class);
        $service->dispatch($transfer, $this->destinationWarehouse->id, $this->actor, 'ผิดคลัง');
    }

    public function test_closed_period_blocks_dispatch(): void
    {
        $this->configureCosting('AVG');
        $this->seedSourceStock('AVG', 'closed');
        $period = DB::table('fiscal_periods')->where('start_date', $this->date)->firstOrFail();
        DB::table('fiscal_periods')->where('id', $period->id)->update(['status' => 'LOCKED']);
        $this->expectException(ValidationException::class);
        app(TransferMovementService::class)->dispatch($this->draft($this->sourceWarehouse->id, $this->destinationWarehouse->id, 'closed'), $this->sourceWarehouse->id, $this->actor, 'งวดปิดแล้ว');
    }

    public function test_insufficient_stock_rolls_back_dispatch_movement(): void
    {
        $this->configureCosting('FIFO');
        $this->seedSourceStock('FIFO', 'short');
        DB::table('wms_stock_balances')->where('warehouse_id', $this->sourceWarehouse->id)->where('item_id', $this->item->id)->update(['on_hand' => 0, 'available' => 0, 'inventory_value' => 0]);
        $transfer = $this->draft($this->sourceWarehouse->id, $this->destinationWarehouse->id, 'short');
        $before = DB::table('wms_stock_movements')->count();
        $this->expectException(ValidationException::class);
        try {
            app(TransferMovementService::class)->dispatch($transfer, $this->sourceWarehouse->id, $this->actor, 'สินค้าไม่พอ');
        } finally {
            $this->assertSame($before, DB::table('wms_stock_movements')->count());
        }
    }

    private function configureCosting(string $method): void
    {
        DB::table('company_settings')->where('id', 1)->update(['inventory_costing_method' => $method, 'allow_negative_stock' => false, 'settings_version' => ((int) $this->settingsBefore['settings_version']) + 1]);
        app(GlobalSettings::class)->forget((int) $this->settingsBefore['settings_version']);
    }

    private function seedSourceStock(string $method, string $key): void
    {
        $movementId = DB::table('wms_stock_movements')->insertGetId(['warehouse_id' => $this->sourceWarehouse->id, 'item_id' => $this->item->id, 'uom_id' => $this->uom->id, 'movement_type' => 'RECEIPT', 'direction' => 'IN', 'status' => 'POSTED', 'quantity' => 10, 'base_quantity' => 10, 'business_date' => $this->date, 'source_type' => 'TEST', 'source_id' => $key, 'idempotency_key' => "test:receipt:{$key}", 'created_at' => now(), 'updated_at' => now()]);
        $layerId = DB::table('wms_stock_cost_layers')->insertGetId(['warehouse_id' => $this->sourceWarehouse->id, 'item_id' => $this->item->id, 'uom_id' => $this->uom->id, 'source_movement_id' => $movementId, 'original_quantity' => 10, 'remaining_quantity' => 10, 'unit_cost' => 10, 'method' => $method, 'cost_status' => 'FINAL', 'business_date' => $this->date, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('wms_stock_balances')->updateOrInsert(['warehouse_id' => $this->sourceWarehouse->id, 'item_id' => $this->item->id, 'uom_id' => $this->uom->id], ['on_hand' => 10, 'reserved' => 0, 'available' => 10, 'inventory_value' => 100, 'average_unit_cost' => 10, 'updated_at' => now()]);
        $this->assertGreaterThan(0, $layerId);
    }

    private function draft(int $source, int $destination, string $key): Transfer
    {
        return app(TransferMovementService::class)->createDraft(['source_warehouse_id' => $source, 'destination_warehouse_id' => $destination, 'document_number' => 'IT-'.$key, 'document_date' => $this->date, 'idempotency_key' => 'test:'.$key], [['item_id' => $this->item->id, 'uom_id' => $this->uom->id, 'planned_quantity' => '10', 'planned_base_quantity' => '10']], $this->actor->id);
    }

    protected function tearDown(): void
    {
        if ($this->settingsBefore !== []) {
            DB::table('company_settings')->where('id', 1)->update($this->settingsBefore);
            app(GlobalSettings::class)->forget((int) $this->settingsBefore['settings_version']);
        }
        parent::tearDown();
    }
}
