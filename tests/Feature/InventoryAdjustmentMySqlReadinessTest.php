<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\InventoryAdjustment;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Models\Uom;
use App\Modules\Wms\Services\InventoryAdjustmentDocumentReversalService;
use App\Modules\Wms\Services\InventoryAdjustmentLiveReversalAdapter;
use App\Modules\Wms\Services\InventoryAdjustmentPostingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Rollback-only local MySQL readiness for typed GAIN/LOSS Adjustment -> GL. */
final class InventoryAdjustmentMySqlReadinessTest extends TestCase
{
    public function test_gain_loss_idempotency_feature_guard_and_failure_rollback(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        $actor = User::query()->first();
        $warehouse = Warehouse::query()->where('is_active', true)->first();
        $item = Item::query()->where('is_active', true)->first();
        $uom = Uom::query()->whereKey($item?->base_uom_id)->first();
        if (! $actor || ! $warehouse || ! $item || ! $uom) {
            $this->markTestSkipped('ต้องมี User/Warehouse/Item/UOM fixture ใน local MySQL');
        }
        $before = $this->counts();
        $previous = config('erp.inventory.adjustment_posting_enabled');
        DB::beginTransaction();
        try {
            $gain = $this->fixture($warehouse->id, $item->id, $uom->id, 'IN', 'GAIN', 'adjustment-gate2-gain', 11, 110);
            $loss = $this->fixture($warehouse->id, $item->id, $uom->id, 'OUT', 'LOSS', 'adjustment-gate2-loss', 4, 40);
            $request = Request::create('/internal/inventory-adjustment', 'POST');
            try {
                app(InventoryAdjustmentPostingService::class)->post($gain['allocation'], $gain['movement'], $warehouse, $actor, $request, $gain['context'], false);
                $this->fail('Expected adjustment feature guard');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('posting', $exception->errors());
            }
            $this->assertSame($before['journals'], $this->counts()['journals']);
            config(['erp.inventory.adjustment_posting_enabled' => true]);
            $postedGain = app(InventoryAdjustmentPostingService::class)->post($gain['allocation'], $gain['movement'], $warehouse, $actor, $request, $gain['context'], true);
            $postedLoss = app(InventoryAdjustmentPostingService::class)->post($loss['allocation'], $loss['movement'], $warehouse, $actor, $request, $loss['context'], true);
            $this->assertNotNull($postedGain->journal_entry_id);
            $this->assertNotNull($postedLoss->journal_entry_id);
            $this->assertSame('POSTED', $postedGain->status);
            $this->assertSame('POSTED', $postedLoss->status);
            $afterPost = $this->counts();
            $retryGain = app(InventoryAdjustmentPostingService::class)->post($postedGain, $gain['movement'], $warehouse, $actor, $request, $gain['context'], true);
            $retryLoss = app(InventoryAdjustmentPostingService::class)->post($postedLoss, $loss['movement'], $warehouse, $actor, $request, $loss['context'], true);
            $this->assertSame((int) $postedGain->journal_entry_id, (int) $retryGain->journal_entry_id);
            $this->assertSame((int) $postedLoss->journal_entry_id, (int) $retryLoss->journal_entry_id);
            $this->assertSame($afterPost, $this->counts());
            try {
                app(InventoryAdjustmentPostingService::class)->post($postedGain, $gain['movement'], $warehouse, $actor, $request, [...$gain['context'], 'reason' => 'changed payload'], true);
                $this->fail('Expected adjustment idempotency mismatch');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('idempotency_key', $exception->errors());
            }
            $this->assertSame($afterPost, $this->counts());
        } finally {
            DB::rollBack();
            config(['erp.inventory.adjustment_posting_enabled' => $previous]);
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_posted_adjustment_reversal_is_immutable_idempotent_and_rollback_safe(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        $actor = User::query()->first();
        $warehouse = Warehouse::query()->where('is_active', true)->first();
        $item = Item::query()->where('is_active', true)->first();
        $uom = Uom::query()->whereKey($item?->base_uom_id)->first();
        if (! $actor || ! $warehouse || ! $item || ! $uom) {
            $this->markTestSkipped('ต้องมี User/Warehouse/Item/UOM fixture ใน local MySQL');
        }
        $previous = config('erp.inventory.adjustment_posting_enabled');
        $before = $this->counts();
        DB::beginTransaction();
        try {
            config(['erp.inventory.adjustment_posting_enabled' => true]);
            $fixture = $this->fixture($warehouse->id, $item->id, $uom->id, 'OUT', 'LOSS', 'adjustment-reversal-gate', 1, 10);
            $request = Request::create('/wms/inventory-adjustments', 'POST');
            $request->attributes->set('selectedWarehouse', $warehouse);
            $posted = app(InventoryAdjustmentPostingService::class)->post($fixture['allocation'], $fixture['movement'], $warehouse, $actor, $request, $fixture['context'], true);
            $adjustment = InventoryAdjustment::query()->create([
                'warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'uom_id' => $uom->id, 'direction' => 'LOSS',
                'status' => 'POSTED', 'reversal_status' => 'NONE', 'quantity' => 1, 'value' => 10, 'business_date' => now()->toDateString(),
                'reason' => 'ตรวจนับเพื่อทดสอบ reversal', 'idempotency_key' => 'adjustment-reversal-gate',
                'stock_movement_id' => $fixture['movement']->id, 'cost_allocation_id' => $posted->id, 'created_by' => $actor->id,
            ]);
            $sourceJournalId = $posted->journal_entry_id;
            $reversed = app(InventoryAdjustmentLiveReversalAdapter::class)->reverse($adjustment, now()->toDateString(), 'แก้ไขยอดตรวจนับผิดพลาด', $actor, $request, true);
            $this->assertSame('REVERSED', $reversed->reversal_status);
            $this->assertNotNull($reversed->reversal_journal_entry_id);
            $this->assertNotSame((int) $sourceJournalId, (int) $reversed->reversal_journal_entry_id);
            $this->assertSame('REVERSED', DB::table('journal_entries')->where('id', $sourceJournalId)->value('status'));
            $this->assertSame('POSTED', DB::table('journal_entries')->where('id', $reversed->reversal_journal_entry_id)->value('status'));
            $retry = app(InventoryAdjustmentLiveReversalAdapter::class)->reverse($reversed, now()->toDateString(), 'แก้ไขยอดตรวจนับผิดพลาด', $actor, $request, true);
            $this->assertSame((int) $reversed->reversal_journal_entry_id, (int) $retry->reversal_journal_entry_id);
            $this->assertSame((int) $reversed->reversal_movement_id, (int) $retry->reversal_movement_id);
            $this->assertSame((int) $reversed->reversal_allocation_id, (int) $retry->reversal_allocation_id);
        } finally {
            DB::rollBack();
            config(['erp.inventory.adjustment_posting_enabled' => $previous]);
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_adjustment_service_owns_movement_allocation_and_rollback_boundary(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        $actor = User::query()->first();
        $warehouse = Warehouse::query()->where('is_active', true)->first();
        $item = Item::query()->where('is_active', true)->first();
        $uom = Uom::query()->whereKey($item?->base_uom_id)->first();
        if (! $actor || ! $warehouse || ! $item || ! $uom) {
            $this->markTestSkipped('ต้องมี User/Warehouse/Item/UOM fixture ใน local MySQL');
        }
        $previous = config('erp.inventory.adjustment_posting_enabled');
        $before = $this->counts();
        DB::beginTransaction();
        try {
            config(['erp.inventory.adjustment_posting_enabled' => true]);
            $adjustment = InventoryAdjustment::query()->create([
                'warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'uom_id' => $uom->id,
                'direction' => 'GAIN', 'status' => 'APPROVED', 'quantity' => 2, 'value' => 20,
                'business_date' => now()->toDateString(), 'reason' => 'ตรวจสอบ service boundary adjustment',
                'idempotency_key' => 'adjustment-service-boundary-gate', 'created_by' => $actor->id,
            ]);
            $request = Request::create('/wms/inventory-adjustments', 'POST');
            $request->attributes->set('selectedWarehouse', $warehouse);
            $posted = app(InventoryAdjustmentPostingService::class)->postAdjustment($adjustment, $warehouse, $actor, $request);
            $this->assertSame('POSTED', $posted->status);
            $this->assertNotNull($posted->stock_movement_id);
            $this->assertNotNull($posted->cost_allocation_id);
            $this->assertNotNull(CostAllocation::query()->find($posted->cost_allocation_id)?->journal_entry_id);
            $projected = StockBalance::query()->where([
                'warehouse_id' => $warehouse->id,
                'item_id' => $item->id,
                'uom_id' => $uom->id,
            ])->first();
            $this->assertNotNull($projected);
            $this->assertGreaterThanOrEqual(2.0, (float) $projected->on_hand);
        } finally {
            DB::rollBack();
            config(['erp.inventory.adjustment_posting_enabled' => $previous]);
        }
        $this->assertSame($before, $this->counts());
    }

    public function test_posted_multi_line_document_reverses_atomically_and_is_idempotent(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรัน dedicated MySQL integration ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
        $actor = User::query()->first();
        $warehouse = Warehouse::query()->where('is_active', true)->first();
        $item = Item::query()->where('is_active', true)->first();
        $uom = Uom::query()->whereKey($item?->base_uom_id)->first();
        if (! $actor || ! $warehouse || ! $item || ! $uom) {
            $this->markTestSkipped('ต้องมี User/Warehouse/Item/UOM fixture ใน local MySQL');
        }
        $before = $this->counts();
        $previous = config('erp.inventory.adjustment_posting_enabled');
        DB::beginTransaction();
        try {
            config(['erp.inventory.adjustment_posting_enabled' => true]);
            StockBalance::query()->updateOrCreate(
                ['warehouse_id' => $warehouse->id, 'item_id' => $item->id, 'uom_id' => $uom->id],
                ['on_hand' => 1000, 'reserved' => 0, 'available' => 1000, 'inventory_value' => 10000, 'average_unit_cost' => 10]
            );
            $first = $this->fixture($warehouse->id, $item->id, $uom->id, 'IN', 'GAIN', 'adjustment-document-reversal-1-'.bin2hex(random_bytes(4)), 2, 20);
            $second = $this->fixture($warehouse->id, $item->id, $uom->id, 'IN', 'GAIN', 'adjustment-document-reversal-2-'.bin2hex(random_bytes(4)), 1, 10);
            $request = Request::create('/wms/inventory-adjustments/documents', 'POST');
            $request->attributes->set('selectedWarehouse', $warehouse);
            $posting = app(InventoryAdjustmentPostingService::class);
            $postedFirst = $posting->post($first['allocation'], $first['movement'], $warehouse, $actor, $request, $first['context'], true);
            $postedSecond = $posting->post($second['allocation'], $second['movement'], $warehouse, $actor, $request, $second['context'], true);
            $document = InventoryAdjustmentDocument::query()->create([
                'warehouse_id' => $warehouse->id,
                'document_number' => 'ADJ-MYSQL-'.bin2hex(random_bytes(5)),
                'document_date' => now()->toDateString(),
                'direction' => 'GAIN',
                'status' => 'POSTED',
                'reversal_status' => 'NONE',
                'reason' => 'ทดสอบกลับรายการเอกสารหลายบรรทัด',
                'idempotency_key' => 'adjustment-document-reversal-'.bin2hex(random_bytes(5)),
                'created_by' => $actor->id,
                'posted_by' => $actor->id,
            ]);
            $lineFirst = InventoryAdjustment::query()->create([
                'document_id' => $document->id, 'line_number' => 1, 'warehouse_id' => $warehouse->id,
                'item_id' => $item->id, 'uom_id' => $uom->id, 'direction' => 'GAIN', 'status' => 'POSTED',
                'reversal_status' => 'NONE', 'quantity' => 2, 'value' => 20, 'business_date' => now()->toDateString(),
                'reason' => 'ทดสอบบรรทัดแรก', 'idempotency_key' => $first['context']['idempotency_key'],
                'stock_movement_id' => $first['movement']->id, 'cost_allocation_id' => $postedFirst->id, 'created_by' => $actor->id,
            ]);
            $lineSecond = InventoryAdjustment::query()->create([
                'document_id' => $document->id, 'line_number' => 2, 'warehouse_id' => $warehouse->id,
                'item_id' => $item->id, 'uom_id' => $uom->id, 'direction' => 'GAIN', 'status' => 'POSTED',
                'reversal_status' => 'NONE', 'quantity' => 1, 'value' => 10, 'business_date' => now()->toDateString(),
                'reason' => 'ทดสอบบรรทัดที่สอง', 'idempotency_key' => $second['context']['idempotency_key'],
                'stock_movement_id' => $second['movement']->id, 'cost_allocation_id' => $postedSecond->id, 'created_by' => $actor->id,
            ]);
            $reason = 'แก้ไขเอกสารตรวจนับหลายบรรทัด';
            $service = app(InventoryAdjustmentDocumentReversalService::class);
            $reversed = $service->reverse($document, now()->toDateString(), $reason, $actor, $request);
            $this->assertSame('REVERSED', $reversed->status);
            $this->assertSame('REVERSED', $reversed->reversal_status);
            $this->assertSame(['REVERSED', 'REVERSED'], $reversed->lines->pluck('reversal_status')->all());
            $this->assertSame('REVERSED', $lineFirst->fresh()->reversal_status);
            $this->assertSame('REVERSED', $lineSecond->fresh()->reversal_status);
            $after = $this->counts();
            $retry = $service->reverse($reversed, now()->toDateString(), $reason, $actor, $request);
            $this->assertSame((int) $reversed->id, (int) $retry->id);
            $this->assertSame($after, $this->counts());
        } finally {
            DB::rollBack();
            config(['erp.inventory.adjustment_posting_enabled' => $previous]);
        }
        $this->assertSame($before, $this->counts());
    }

    /** @return array{movement: StockMovement, allocation: CostAllocation, context: array<string,mixed>} */
    private function fixture(int $warehouse, int $item, int $uom, string $movementDirection, string $direction, string $key, int $quantity, int $value): array
    {
        $movement = StockMovement::query()->create([
            'warehouse_id' => $warehouse, 'item_id' => $item, 'uom_id' => $uom, 'movement_type' => 'ADJUSTMENT', 'direction' => $movementDirection,
            'status' => 'POSTED', 'quantity' => $quantity, 'base_quantity' => $quantity, 'business_date' => now()->toDateString(),
            'source_type' => 'INVENTORY', 'source_id' => $key, 'source_reference' => strtoupper($key), 'idempotency_key' => $key.':movement', 'created_by' => 1, 'posted_at' => now(),
        ]);
        $allocation = CostAllocation::query()->create([
            'stock_movement_id' => $movement->id, 'warehouse_id' => $warehouse, 'item_id' => $item, 'uom_id' => $uom, 'allocation_type' => 'ADJUSTMENT',
            'direction' => $movementDirection, 'cost_status' => 'FINAL', 'status' => 'PENDING', 'method' => 'AVG', 'policy_version' => 'costing-v1', 'revision' => 0,
            'quantity' => $quantity, 'unit_cost' => $value / $quantity, 'value' => $value, 'business_date' => now()->toDateString(), 'idempotency_key' => $key.':allocation:'.$movement->id,
        ]);

        return ['movement' => $movement, 'allocation' => $allocation, 'context' => ['source_type' => 'INVENTORY', 'source_id' => $key, 'source_reference' => strtoupper($key), 'warehouse_id' => $warehouse, 'item_id' => $item, 'uom_id' => $uom, 'business_date' => now()->toDateString(), 'quantity' => (string) $quantity, 'value' => (string) $value, 'direction' => $direction, 'idempotency_key' => $key.':allocation:'.$allocation->id, 'reason' => 'ตรวจนับสต็อกเพื่อยืนยันยอด', 'approved' => true, 'period_open' => true, 'reconciled' => true]];
    }

    /** @return array{journals:int,allocations:int,links:int} */
    private function counts(): array
    {
        return ['journals' => DB::table('journal_entries')->count(), 'allocations' => DB::table('wms_cost_allocations')->count(), 'links' => DB::table('wms_cost_allocation_journal_lines')->count()];
    }
}
