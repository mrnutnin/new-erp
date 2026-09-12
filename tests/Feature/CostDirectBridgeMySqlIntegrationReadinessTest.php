<?php

namespace Tests\Feature;

use App\Modules\Wms\Services\CostDirectBridgeResolver;
use App\Modules\Wms\Services\CostShadowCalculationService;
use App\Modules\Wms\Services\ProductionBridgeResolver;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CostDirectBridgeMySqlIntegrationReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
    }

    public function test_issue_return_and_its_reversal_resolve_from_real_explicit_parents_without_writes(): void
    {
        $return = DB::table('wms_cost_allocations as child')
            ->join('wms_stock_movements as movement', 'movement.id', '=', 'child.stock_movement_id')
            ->where('movement.source_type', 'ISSUE_RETURN')->where('child.direction', 'IN')
            ->whereNotNull('child.parent_allocation_id')->orderByDesc('child.id')
            ->first(['child.id', 'child.parent_allocation_id']);
        if (! $return) {
            $this->markTestSkipped('ยังไม่มี Issue Return explicit-parent fixture ในฐานข้อมูลนี้');
        }
        $reversal = DB::table('wms_cost_allocations')->where('parent_allocation_id', $return->id)->where('direction', 'OUT')->orderByDesc('id')->first(['id']);
        if (! $reversal) {
            $this->markTestSkipped('ยังไม่มี Issue Return reversal fixture ในฐานข้อมูลนี้');
        }

        $before = $this->ledgerCounts();
        $returnResult = app(CostDirectBridgeResolver::class)->resolve([$this->parent((int) $return->parent_allocation_id)]);
        $reversalResult = app(CostDirectBridgeResolver::class)->resolve([$this->parent((int) $return->id)]);

        self::assertSame('ISSUE_RETURN', collect($returnResult['edges'])->firstWhere('child_allocation_id', (int) $return->id)['relation']);
        self::assertSame('REVERSAL', collect($reversalResult['edges'])->firstWhere('child_allocation_id', (int) $reversal->id)['relation']);
        self::assertSame($before, $this->ledgerCounts());
    }

    public function test_transfer_accept_resolves_to_its_actual_destination_partition_without_writes(): void
    {
        $accept = DB::table('wms_cost_allocations as child')
            ->join('wms_stock_movements as movement', 'movement.id', '=', 'child.stock_movement_id')
            ->where('movement.source_type', 'WMS_TRANSFER')
            ->where('movement.direction', 'IN')
            ->where('movement.metadata->transfer_event', 'ACCEPT')
            ->whereNotNull('child.parent_allocation_id')
            ->where('child.allocation_type', '!=', 'RECOST')
            ->orderByDesc('child.id')
            ->first(['child.id', 'child.parent_allocation_id', 'child.warehouse_id']);
        if (! $accept) {
            $this->markTestSkipped('ยังไม่มี Transfer Accept explicit-parent fixture ในฐานข้อมูลนี้');
        }

        $before = $this->ledgerCounts();
        $result = app(CostDirectBridgeResolver::class)->resolve([$this->parent((int) $accept->parent_allocation_id)]);
        $edge = collect($result['edges'])->firstWhere('child_allocation_id', (int) $accept->id);

        self::assertSame('TRANSFER_ACCEPT', $edge['relation']);
        self::assertTrue($edge['cross_partition']);
        self::assertStringStartsWith((int) $accept->warehouse_id.':', $edge['target_partition_key']);
        self::assertNotContains("ALLOCATION_{$accept->id}:DIRECT_BRIDGE_EVENT_UNSUPPORTED", $result['blockers']);
        self::assertSame($before, $this->ledgerCounts());
    }

    public function test_transfer_accept_replays_its_destination_timeline_without_writes(): void
    {
        $accept = DB::table('wms_cost_allocations as child')
            ->join('wms_stock_movements as movement', 'movement.id', '=', 'child.stock_movement_id')
            ->where('movement.source_type', 'WMS_TRANSFER')
            ->where('movement.direction', 'IN')
            ->where('movement.metadata->transfer_event', 'ACCEPT')
            ->whereNotNull('child.parent_allocation_id')
            ->where('child.allocation_type', '!=', 'RECOST')
            ->orderByDesc('child.id')
            ->first(['child.id', 'child.parent_allocation_id']);
        if (! $accept) {
            $this->markTestSkipped('ยังไม่มี Transfer Accept explicit-parent fixture ในฐานข้อมูลนี้');
        }
        $parent = DB::table('wms_cost_allocations')->where('id', $accept->parent_allocation_id)->first(['unit_cost']);
        $proposed = number_format((float) $parent->unit_cost + 1, 8, '.', '');

        $before = $this->ledgerCounts();
        $result = app(CostShadowCalculationService::class)->calculate((int) $accept->parent_allocation_id, $proposed);

        self::assertGreaterThanOrEqual(1, $result['summary']['transfer_replay_partitions']);
        self::assertSame((int) $accept->id, $result['transfer_replays']['partitions'][0]['root']['allocation_id']);
        self::assertSame('0.00000000', $result['recursive_impact_summary']['source_delta_value']);
        self::assertMatchesRegularExpression('/^-?\d+\.\d{8}$/', $result['recursive_impact_summary']['rounding_residual_value']);
        self::assertSame($before, $this->ledgerCounts());
    }

    public function test_material_issue_replays_linked_finished_outputs_without_writes(): void
    {
        $source = DB::table('wms_inventory_adjustment_documents as receipt')
            ->join('wms_issue_lines as line', 'line.document_id', '=', 'receipt.source_issue_id')
            ->join('wms_cost_allocations as allocation', 'allocation.stock_movement_id', '=', 'line.stock_movement_id')
            ->join('wms_stock_movements as movement', 'movement.id', '=', 'allocation.stock_movement_id')
            ->where('receipt.document_context', 'PRODUCTION_RECEIPT')->where('receipt.status', 'POSTED')
            ->where('movement.status', 'POSTED')->where('allocation.status', '!=', 'REVERSED')->where('allocation.cost_status', 'FINAL')
            ->whereNull('line.deleted_at')->orderByDesc('receipt.id')->orderBy('allocation.id')
            ->first(['allocation.id', 'allocation.unit_cost']);
        if (! $source) {
            $this->markTestSkipped('ยังไม่มี Material Issue → Finished Receipt fixture ที่ Post แล้วในฐานข้อมูลนี้');
        }

        $before = $this->ledgerCounts();
        $proposed = BigDecimal::of((string) $source->unit_cost)->plus('0.01000000')->toScale(8)->__toString();
        $result = app(CostShadowCalculationService::class)->calculate((int) $source->id, $proposed);

        self::assertNotEmpty($result['production_bridges']['edges']);
        self::assertGreaterThanOrEqual(1, $result['summary']['production_replay_partitions']);
        self::assertSame('0.00000000', $result['recursive_impact_summary']['rounding_residual_value']);
        self::assertSame($before, $this->ledgerCounts());
    }

    public function test_normalized_many_source_many_receipt_outputs_reach_transfer_without_writes(): void
    {
        if (! Schema::hasTable('wms_production_receipt_sources')) {
            $this->markTestSkipped('กรุณา Prepare Database ผ่าน Installer เพื่อติดตั้ง normalized Production Bridge ก่อน');
        }

        $actorId = (int) DB::table('users')->orderBy('id')->value('id');
        $warehouse = DB::table('warehouses')->where('is_active', true)->whereNull('deleted_at')->orderBy('id')->first();
        $categoryId = (int) DB::table('wms_item_categories')->orderBy('id')->value('id');
        $uomId = (int) DB::table('wms_uoms')->where('is_active', true)->orderBy('id')->value('id');
        if ($actorId < 1 || ! $warehouse || $categoryId < 1 || $uomId < 1) {
            $this->markTestSkipped('ต้องมี User, Warehouse, Item Category และ UOM สำหรับ integration fixture');
        }

        DB::beginTransaction();
        try {
            $stamp = substr((string) hrtime(true), -10);
            $date = now()->toDateString();
            $destinationId = DB::table('warehouses')->insertGetId([
                'branch_id' => $warehouse->branch_id, 'code' => 'PB-'.$stamp, 'name' => 'Production Bridge Test',
                'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $materialId = $this->item($categoryId, $uomId, 'PB-M-'.$stamp, $actorId);
            $finishedId = $this->item($categoryId, $uomId, 'PB-F-'.$stamp, $actorId);
            $sourceA = $this->materialIssue((int) $warehouse->id, $materialId, $uomId, $actorId, $date, 'A-'.$stamp);
            $sourceB = $this->materialIssue((int) $warehouse->id, $materialId, $uomId, $actorId, $date, 'B-'.$stamp);
            $receiptA = $this->finishedReceipt((int) $warehouse->id, (int) $warehouse->branch_id, $finishedId, $uomId, $actorId, $date, 'A-'.$stamp, [$sourceA, $sourceB], ['70', '50'], '6', '60');
            $receiptB = $this->finishedReceipt((int) $warehouse->id, (int) $warehouse->branch_id, $finishedId, $uomId, $actorId, $date, 'B-'.$stamp, [$sourceA, $sourceB], ['30', '50'], '4', '40');
            $transferAllocation = $this->transferAccept($receiptA['allocations'][0], (int) $warehouse->id, $destinationId, $finishedId, $uomId, $actorId, $date, $stamp);

            $before = $this->ledgerCounts();
            $parents = collect([$sourceA, $sourceB])->map(fn (array $source): array => $this->productionParent($source['allocation_id'], (int) $warehouse->id, $materialId, $uomId, $date))->all();
            $bridge = app(ProductionBridgeResolver::class)->resolve($parents);
            $shadow = app(CostShadowCalculationService::class)->calculate($sourceA['allocation_id'], '11.00000000');

            self::assertTrue($bridge['ready'], implode(', ', $bridge['blockers']));
            self::assertCount(8, $bridge['edges']);
            self::assertSame([$receiptA['document_id'], $receiptB['document_id']], collect($bridge['edges'])->pluck('receipt_document_id')->unique()->sort()->values()->all());
            self::assertSame(4, collect($bridge['edges'])->pluck('child_allocation_id')->unique()->count());
            $transferRoots = collect($shadow['transfer_replays']['partitions'])->pluck('root.allocation_id')->all();
            self::assertContains($transferAllocation, $transferRoots, json_encode([
                'transfer_roots' => $transferRoots,
                'bridge_blockers' => $shadow['bridge_replays']['blockers'],
                'production_edges' => $shadow['production_bridges']['edges'],
                'rows' => collect($shadow['rows'])->map(fn (array $row): array => array_intersect_key($row, array_flip(['allocation_id', 'impact_bucket', 'estimated_delta_value', 'issues'])))->all(),
            ], JSON_UNESCAPED_SLASHES));
            self::assertSame('0.00000000', $shadow['recursive_impact_summary']['rounding_residual_value']);
            self::assertSame($before, $this->ledgerCounts());
        } finally {
            DB::rollBack();
        }
    }

    /** @return array<string, mixed> */
    private function parent(int $id): array
    {
        $allocation = DB::table('wms_cost_allocations')->where('id', $id)->first();

        return [
            'allocation_id' => (int) $allocation->id,
            'warehouse_id' => (int) $allocation->warehouse_id,
            'item_id' => (int) $allocation->item_id,
            'uom_id' => (int) $allocation->uom_id,
            'method' => (string) $allocation->method,
            'direction' => (string) $allocation->direction,
            'quantity' => (string) $allocation->quantity,
            'estimated_delta_value' => $allocation->direction === 'OUT' ? '-1.00000000' : '1.00000000',
            'business_date' => (string) $allocation->business_date,
        ];
    }

    private function item(int $categoryId, int $uomId, string $code, int $actorId): int
    {
        return DB::table('wms_items')->insertGetId([
            'category_id' => $categoryId, 'code' => $code, 'name' => $code, 'item_type' => 'GOODS',
            'base_uom' => 'TEST', 'base_uom_id' => $uomId, 'is_stock_item' => true, 'is_active' => true,
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array{document_id:int,line_id:int,allocation_id:int} */
    private function materialIssue(int $warehouseId, int $itemId, int $uomId, int $actorId, string $date, string $key): array
    {
        $documentId = DB::table('wms_issue_documents')->insertGetId([
            'warehouse_id' => $warehouseId, 'document_number' => 'PB-ISSUE-'.$key, 'document_date' => $date,
            'status' => 'POSTED', 'issue_type' => 'PRODUCTION', 'reason' => 'Production bridge integration',
            'idempotency_key' => 'pb:issue:'.$key, 'created_by' => $actorId, 'approved_by' => $actorId,
            'posted_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $movementId = $this->movement($warehouseId, $itemId, $uomId, 'ISSUE', 'OUT', '10', $date, 'ISSUE_DOCUMENT', (string) $documentId, 'PB-ISSUE-'.$key, 'pb:issue:movement:'.$key, $actorId, ['issue_type' => 'PRODUCTION']);
        $allocationId = $this->allocation($movementId, $warehouseId, $itemId, $uomId, 'ISSUE', 'OUT', '10', '-100', $date, 'pb:issue:allocation:'.$key);
        $lineId = DB::table('wms_issue_lines')->insertGetId([
            'document_id' => $documentId, 'item_id' => $itemId, 'uom_id' => $uomId, 'quantity' => 10,
            'stock_movement_id' => $movementId, 'cost_allocation_id' => $allocationId, 'line_number' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['document_id' => $documentId, 'line_id' => $lineId, 'allocation_id' => $allocationId];
    }

    /** @param list<array{document_id:int,line_id:int,allocation_id:int}> $sources @param list<string> $values @return array{document_id:int,allocations:list<int>} */
    private function finishedReceipt(int $warehouseId, int $branchId, int $itemId, int $uomId, int $actorId, string $date, string $key, array $sources, array $values, string $sourceQuantity, string $sourceValue): array
    {
        $documentId = DB::table('wms_inventory_adjustment_documents')->insertGetId([
            'warehouse_id' => $warehouseId, 'branch_id' => $branchId, 'document_number' => 'PB-RECEIPT-'.$key,
            'document_date' => $date, 'direction' => 'GAIN', 'document_context' => 'PRODUCTION_RECEIPT',
            'source_issue_id' => $sources[0]['document_id'], 'status' => 'POSTED', 'reason' => 'Production bridge integration',
            'idempotency_key' => 'pb:receipt:'.$key, 'created_by' => $actorId, 'approved_by' => $actorId,
            'posted_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $allocations = [];
        foreach ($values as $position => $value) {
            $movementId = $this->movement($warehouseId, $itemId, $uomId, 'RECEIPT', 'IN', '1', $date, 'WMS_PRODUCTION_RECEIPT', (string) $documentId, 'PB-RECEIPT-'.$key, 'pb:receipt:movement:'.$key.':'.$position, $actorId);
            $allocationId = $this->allocation($movementId, $warehouseId, $itemId, $uomId, 'PRODUCTION', 'IN', '1', $value, $date, 'pb:receipt:allocation:'.$key.':'.$position);
            DB::table('wms_inventory_adjustments')->insert([
                'document_id' => $documentId, 'line_number' => $position + 1, 'warehouse_id' => $warehouseId,
                'item_id' => $itemId, 'uom_id' => $uomId, 'direction' => 'GAIN', 'status' => 'POSTED',
                'quantity' => 1, 'value' => $value, 'business_date' => $date, 'reason' => 'Production bridge integration',
                'idempotency_key' => 'pb:receipt:line:'.$key.':'.$position, 'stock_movement_id' => $movementId,
                'cost_allocation_id' => $allocationId, 'created_by' => $actorId, 'approved_by' => $actorId,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $allocations[] = $allocationId;
        }
        foreach ($sources as $position => $source) {
            DB::table('wms_production_receipt_sources')->insert([
                'receipt_document_id' => $documentId, 'issue_document_id' => $source['document_id'],
                'issue_line_id' => $source['line_id'], 'source_allocation_id' => $source['allocation_id'],
                'source_allocation_revision' => 0, 'consumed_quantity' => $sourceQuantity,
                'consumed_value' => $sourceValue, 'position' => $position + 1, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return ['document_id' => $documentId, 'allocations' => $allocations];
    }

    private function transferAccept(int $parentAllocationId, int $sourceWarehouseId, int $destinationWarehouseId, int $itemId, int $uomId, int $actorId, string $date, string $key): int
    {
        $transferId = DB::table('wms_transfers')->insertGetId([
            'source_warehouse_id' => $sourceWarehouseId, 'destination_warehouse_id' => $destinationWarehouseId,
            'document_number' => 'PB-TRANSFER-'.$key, 'document_date' => $date, 'status' => 'ACCEPTED',
            'idempotency_key' => 'pb:transfer:'.$key, 'created_by' => $actorId, 'dispatched_by' => $actorId,
            'dispatched_at' => now(), 'completed_by' => $actorId, 'completed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $lineId = DB::table('wms_transfer_lines')->insertGetId([
            'transfer_id' => $transferId, 'item_id' => $itemId, 'uom_id' => $uomId, 'line_number' => 1,
            'planned_quantity' => 1, 'planned_base_quantity' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $dispatchMovementId = $this->movement($sourceWarehouseId, $itemId, $uomId, 'TRANSFER', 'OUT', '1', $date, 'WMS_TRANSFER', (string) $transferId, 'PB-TRANSFER-'.$key, 'pb:transfer:dispatch:movement:'.$key, $actorId, ['transfer_event' => 'DISPATCH']);
        $dispatchAllocationId = $this->allocation($dispatchMovementId, $sourceWarehouseId, $itemId, $uomId, 'TRANSFER', 'OUT', '1', '-70', $date, 'pb:transfer:dispatch:allocation:'.$key, $parentAllocationId, ['transfer_event' => 'DISPATCH']);
        DB::table('wms_transfer_events')->insert([
            'transfer_id' => $transferId, 'transfer_line_id' => $lineId, 'event_type' => 'DISPATCH',
            'quantity' => 1, 'base_quantity' => 1, 'business_date' => $date, 'stock_movement_id' => $dispatchMovementId,
            'idempotency_key' => 'pb:transfer:dispatch:event:'.$key, 'source_reference' => 'PB-TRANSFER-'.$key,
            'reason' => 'Production bridge integration', 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $movementId = $this->movement($destinationWarehouseId, $itemId, $uomId, 'TRANSFER', 'IN', '1', $date, 'WMS_TRANSFER', (string) $transferId, 'PB-TRANSFER-'.$key, 'pb:transfer:accept:movement:'.$key, $actorId, ['transfer_event' => 'ACCEPT']);
        DB::table('wms_transfer_events')->insert([
            'transfer_id' => $transferId, 'transfer_line_id' => $lineId, 'event_type' => 'ACCEPT',
            'quantity' => 1, 'base_quantity' => 1, 'business_date' => $date, 'stock_movement_id' => $movementId,
            'idempotency_key' => 'pb:transfer:accept:event:'.$key, 'source_reference' => 'PB-TRANSFER-'.$key,
            'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $this->allocation($movementId, $destinationWarehouseId, $itemId, $uomId, 'TRANSFER', 'IN', '1', '70', $date, 'pb:transfer:accept:allocation:'.$key, $dispatchAllocationId, ['transfer_event' => 'ACCEPT']);
    }

    /** @param array<string,mixed> $metadata */
    private function movement(int $warehouseId, int $itemId, int $uomId, string $type, string $direction, string $quantity, string $date, string $sourceType, string $sourceId, string $reference, string $key, int $actorId, array $metadata = []): int
    {
        return DB::table('wms_stock_movements')->insertGetId([
            'warehouse_id' => $warehouseId, 'item_id' => $itemId, 'uom_id' => $uomId, 'movement_type' => $type,
            'direction' => $direction, 'status' => 'POSTED', 'quantity' => $quantity, 'base_quantity' => $quantity,
            'business_date' => $date, 'source_type' => $sourceType, 'source_id' => $sourceId,
            'source_reference' => $reference, 'idempotency_key' => $key, 'metadata' => json_encode($metadata),
            'posted_at' => now(), 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param array<string,mixed> $metadata */
    private function allocation(int $movementId, int $warehouseId, int $itemId, int $uomId, string $type, string $direction, string $quantity, string $value, string $date, string $key, ?int $parentId = null, array $metadata = []): int
    {
        return DB::table('wms_cost_allocations')->insertGetId([
            'stock_movement_id' => $movementId, 'parent_allocation_id' => $parentId, 'warehouse_id' => $warehouseId,
            'item_id' => $itemId, 'uom_id' => $uomId, 'allocation_type' => $type, 'direction' => $direction,
            'cost_status' => 'FINAL', 'status' => 'POSTED', 'method' => 'AVG', 'policy_version' => 'test-v1',
            'revision' => 0, 'quantity' => $quantity, 'unit_cost' => BigDecimal::of($value)->abs()->dividedBy($quantity, 8)->__toString(),
            'value' => $value, 'business_date' => $date, 'idempotency_key' => $key, 'metadata' => json_encode($metadata),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    private function productionParent(int $allocationId, int $warehouseId, int $itemId, int $uomId, string $date): array
    {
        return ['allocation_id' => $allocationId, 'warehouse_id' => $warehouseId, 'item_id' => $itemId, 'uom_id' => $uomId,
            'method' => 'AVG', 'direction' => 'OUT', 'quantity' => '10', 'estimated_delta_value' => '-10.00000000',
            'business_date' => $date, 'impact_bucket' => 'WIP_CONSUMED'];
    }

    /** @return array<string, int> */
    private function ledgerCounts(): array
    {
        return collect(['wms_stock_movements', 'wms_cost_allocations', 'wms_cost_revaluation_runs', 'wms_cost_revaluation_deltas'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
