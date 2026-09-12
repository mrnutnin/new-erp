<?php

namespace Tests\Unit;

use App\Modules\Wms\Services\CostLineageExplorer;
use App\Modules\Wms\Services\CostShadowCalculationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CostLineageExplorerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('branches', function (Blueprint $table): void {
            $table->id();
            $table->string('code');
            $table->string('name');
        });
        Schema::create('warehouses', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('branch_id');
            $table->string('code');
            $table->string('name');
            $table->softDeletes();
        });
        DB::table('branches')->insert(['id' => 1, 'code' => 'HQ', 'name' => 'Head Office']);
        DB::table('warehouses')->insert(['id' => 1, 'branch_id' => 1, 'code' => 'WH1', 'name' => 'Main']);
        DB::table('warehouses')->insert(['id' => 2, 'branch_id' => 1, 'code' => 'WH2', 'name' => 'Destination']);
        DB::table('warehouses')->insert(['id' => 3, 'branch_id' => 1, 'code' => 'WH3', 'name' => 'Downstream']);
        Schema::create('wms_stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('uom_id');
            $table->string('movement_type');
            $table->string('direction');
            $table->string('status');
            $table->decimal('quantity', 20, 8);
            $table->decimal('base_quantity', 20, 8);
            $table->date('business_date');
            $table->string('source_type')->nullable();
            $table->string('source_id')->nullable();
            $table->string('source_reference')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('wms_transfer_events', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_movement_id');
            $table->date('business_date');
        });
        Schema::create('wms_issue_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->string('issue_type');
            $table->string('status');
            $table->date('document_date');
            $table->softDeletes();
        });
        Schema::create('wms_issue_lines', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedBigInteger('stock_movement_id');
            $table->softDeletes();
        });
        Schema::create('wms_inventory_adjustment_documents', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('source_issue_id')->nullable();
            $table->string('document_context');
            $table->string('status');
            $table->date('document_date');
        });
        Schema::create('wms_inventory_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('document_id');
            $table->unsignedInteger('line_number');
            $table->unsignedBigInteger('cost_allocation_id');
        });
        Schema::create('wms_cost_allocations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('stock_movement_id');
            $table->unsignedBigInteger('stock_cost_layer_id')->nullable();
            $table->unsignedBigInteger('recost_request_id')->nullable();
            $table->unsignedBigInteger('parent_allocation_id')->nullable();
            $table->unsignedBigInteger('journal_entry_id')->nullable();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('uom_id');
            $table->string('allocation_type');
            $table->string('direction');
            $table->string('cost_status');
            $table->string('status');
            $table->string('method');
            $table->string('policy_version');
            $table->unsignedInteger('revision')->default(0);
            $table->decimal('quantity', 20, 8);
            $table->decimal('unit_cost', 20, 8);
            $table->decimal('value', 20, 8);
            $table->date('business_date');
            $table->string('idempotency_key')->unique();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });
        Schema::create('wms_production_receipt_sources', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('receipt_document_id');
            $table->unsignedBigInteger('issue_document_id');
            $table->unsignedBigInteger('issue_line_id');
            $table->unsignedBigInteger('source_allocation_id');
            $table->decimal('consumed_quantity', 20, 8);
            $table->decimal('consumed_value', 20, 8);
            $table->unsignedInteger('position');
        });
        Schema::create('wms_stock_balances', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('warehouse_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('uom_id');
            $table->decimal('on_hand', 20, 8)->default(0);
            $table->decimal('reserved', 20, 8)->default(0);
            $table->decimal('available', 20, 8)->default(0);
            $table->decimal('inventory_value', 20, 8)->default(0);
            $table->decimal('average_unit_cost', 20, 8)->default(0);
        });
        Schema::create('fiscal_periods', function (Blueprint $table): void {
            $table->id();
            $table->date('start_date');
            $table->date('end_date');
            $table->string('status');
        });
        DB::table('fiscal_periods')->insert(['start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'status' => 'OPEN']);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('wms_production_receipt_sources');
        Schema::dropIfExists('wms_cost_allocations');
        Schema::dropIfExists('wms_inventory_adjustments');
        Schema::dropIfExists('wms_inventory_adjustment_documents');
        Schema::dropIfExists('wms_issue_lines');
        Schema::dropIfExists('wms_issue_documents');
        Schema::dropIfExists('wms_transfer_events');
        Schema::dropIfExists('wms_stock_movements');
        Schema::dropIfExists('wms_stock_balances');
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('branches');
        parent::tearDown();
    }

    public function test_parent_closure_includes_cross_warehouse_parent(): void
    {
        $parent = $this->allocation(['warehouse_id' => 2, 'source_reference' => 'TR-OUT']);
        $this->allocation(['warehouse_id' => 1, 'parent_allocation_id' => $parent, 'source_reference' => 'TR-IN']);

        $snapshot = app(CostLineageExplorer::class)->snapshot(1);

        $this->assertSame(2, $snapshot['summary']['nodes']);
        $this->assertSame(1, $snapshot['summary']['edges']);
        $this->assertSame(0, $snapshot['summary']['missing_parents']);
    }

    public function test_missing_parent_is_reported_without_failing_read(): void
    {
        $this->allocation(['warehouse_id' => 1, 'parent_allocation_id' => 999, 'source_reference' => 'ORPHAN']);

        $snapshot = app(CostLineageExplorer::class)->snapshot(1);

        $this->assertSame(1, $snapshot['summary']['nodes']);
        $this->assertSame(1, $snapshot['summary']['missing_parents']);
        $this->assertSame(['missing_parent'], $snapshot['rows'][0]['issues']);
    }

    public function test_cycle_is_reported_without_writing_anything(): void
    {
        $first = $this->allocation(['warehouse_id' => 1, 'source_reference' => 'CYCLE-A']);
        $second = $this->allocation(['warehouse_id' => 1, 'parent_allocation_id' => $first, 'source_reference' => 'CYCLE-B']);
        DB::table('wms_cost_allocations')->where('id', $first)->update(['parent_allocation_id' => $second]);

        $snapshot = app(CostLineageExplorer::class)->snapshot(1);

        $this->assertSame(2, $snapshot['summary']['cycles']);
        $this->assertCount(2, array_filter($snapshot['rows'], fn (array $row): bool => in_array('cycle', $row['issues'], true)));
    }

    public function test_shadow_calculation_replays_avg_timeline_and_reconciles_without_writes(): void
    {
        $root = $this->allocation(['source_reference' => 'BACKDATED-RECEIPT', 'quantity' => '10', 'value' => '100', 'business_date' => '2026-09-01']);
        $this->allocation(['parent_allocation_id' => $root, 'source_reference' => 'FUTURE-OUT', 'direction' => 'OUT', 'quantity' => '4', 'value' => '-40', 'business_date' => '2026-09-07']);
        DB::table('wms_stock_balances')->insert(['warehouse_id' => 1, 'item_id' => 10, 'uom_id' => 20, 'on_hand' => 6, 'reserved' => 0, 'available' => 6, 'inventory_value' => 60, 'average_unit_cost' => 10]);

        $result = app(CostShadowCalculationService::class)->calculate($root, '12.00000000');

        $this->assertTrue($result['read_only']);
        $this->assertSame('cost-shadow-v2-avg-canonical-movement-quantity-legacy-proof', $result['calculation_contract_version']);
        $this->assertSame(2, $result['summary']['affected_nodes']);
        $this->assertSame('20.00000000', $result['rows'][0]['estimated_delta_value']);
        $this->assertSame('-8.00000000', $result['rows'][1]['estimated_delta_value']);
        $this->assertSame('20.00000000', $result['impact_summary']['source_delta_value']);
        $this->assertSame('12.00000000', $result['impact_summary']['ending_on_hand_delta_value']);
        $this->assertSame('8.00000000', $result['impact_summary']['open_bridge_delta_value']);
        $this->assertSame('0.00000000', $result['impact_summary']['rounding_residual_value']);
        $this->assertSame('OPEN', $result['summary']['period_status']);
        $this->assertSame('READY_FOR_REVIEW', $result['variance_report']['status']);
        $this->assertSame([], $result['fifo_evidence']);
        $this->assertSame('60.00000000', $result['valuation']['current_inventory_value']);
        $this->assertSame('72.00000000', $result['valuation']['estimated_inventory_value_after_delta']);
        $this->assertSame(2, DB::table('wms_cost_allocations')->count());
    }

    public function test_shadow_rebuilds_fifo_anchor_before_the_document_date_and_consumes_exact_layers(): void
    {
        $this->allocation(['method' => 'FIFO', 'stock_cost_layer_id' => 900, 'quantity' => '10', 'unit_cost' => '10', 'value' => '100', 'business_date' => '2026-09-01']);
        $this->allocation(['method' => 'FIFO', 'stock_cost_layer_id' => 900, 'direction' => 'OUT', 'quantity' => '4', 'unit_cost' => '10', 'value' => '-40', 'business_date' => '2026-09-02']);
        $root = $this->allocation(['method' => 'FIFO', 'stock_cost_layer_id' => 901, 'quantity' => '5', 'unit_cost' => '20', 'value' => '100', 'business_date' => '2026-09-03']);
        $this->allocation(['method' => 'FIFO', 'stock_cost_layer_id' => 900, 'direction' => 'OUT', 'quantity' => '2', 'unit_cost' => '10', 'value' => '-20', 'business_date' => '2026-09-04']);
        $this->allocation(['method' => 'FIFO', 'stock_cost_layer_id' => 901, 'direction' => 'OUT', 'quantity' => '3', 'unit_cost' => '20', 'value' => '-60', 'business_date' => '2026-09-04']);
        DB::table('wms_stock_balances')->insert(['warehouse_id' => 1, 'item_id' => 10, 'uom_id' => 20, 'on_hand' => 6, 'reserved' => 0, 'available' => 6, 'inventory_value' => 80, 'average_unit_cost' => 13.33333333]);

        $result = app(CostShadowCalculationService::class)->calculate($root, '22.00000000');

        $this->assertSame('cost-shadow-v2-fifo', $result['calculation_contract_version']);
        $this->assertSame('READY', $result['summary']['anchor_status']);
        $this->assertSame(2, $result['summary']['anchor_rows']);
        $this->assertSame('10.00000000', $result['impact_summary']['source_delta_value']);
        $this->assertSame('4.00000000', $result['impact_summary']['ending_on_hand_delta_value']);
        $this->assertSame('6.00000000', $result['impact_summary']['open_bridge_delta_value']);
        $this->assertSame('0.00000000', $result['impact_summary']['rounding_residual_value']);
        $this->assertSame('0.00000000', $result['rows'][1]['estimated_delta_value']);
        $this->assertSame('-6.00000000', $result['rows'][2]['estimated_delta_value']);
        $this->assertSame('READY_FOR_REVIEW', $result['variance_report']['status']);
        $this->assertSame(5, DB::table('wms_cost_allocations')->count());
    }

    public function test_shadow_parent_bridge_reconciles_out_and_partial_in_without_double_counting(): void
    {
        $root = $this->allocation(['source_reference' => 'TRANSFER-COST-ROOT', 'quantity' => '10', 'value' => '100', 'business_date' => '2026-09-01']);
        $out = $this->allocation(['parent_allocation_id' => $root, 'source_reference' => 'TRANSFER-OUT', 'direction' => 'OUT', 'quantity' => '10', 'value' => '-100', 'business_date' => '2026-09-02']);
        $this->allocation(['parent_allocation_id' => $out, 'source_reference' => 'TRANSFER-IN', 'direction' => 'IN', 'quantity' => '4', 'value' => '40', 'business_date' => '2026-09-03']);
        DB::table('wms_stock_balances')->insert(['warehouse_id' => 1, 'item_id' => 10, 'uom_id' => 20, 'on_hand' => 4, 'reserved' => 0, 'available' => 4, 'inventory_value' => 40, 'average_unit_cost' => 10]);

        $result = app(CostShadowCalculationService::class)->calculate($root, '12.00000000');

        $this->assertSame(['20.00000000', '-20.00000000', '8.00000000'], array_column($result['rows'], 'estimated_delta_value'));
        $this->assertSame('20.00000000', $result['impact_summary']['source_delta_value']);
        $this->assertSame('8.00000000', $result['impact_summary']['ending_on_hand_delta_value']);
        $this->assertSame('12.00000000', $result['impact_summary']['open_bridge_delta_value']);
        $this->assertSame('0.00000000', $result['impact_summary']['rounding_residual_value']);
        $this->assertSame('READY_FOR_REVIEW', $result['variance_report']['status']);
    }

    public function test_shadow_recursively_replays_transfer_accept_across_destination_partitions(): void
    {
        $root = $this->allocation(['source_reference' => 'TRANSFER-ROOT', 'quantity' => '10', 'value' => '100', 'business_date' => '2026-09-01']);
        $out = $this->allocation(['parent_allocation_id' => $root, 'source_reference' => 'TR-A-B', 'direction' => 'OUT', 'quantity' => '10', 'value' => '-100', 'business_date' => '2026-09-02', 'metadata' => ['transfer_event' => 'DISPATCH']]);
        $this->allocation(['parent_allocation_id' => $out, 'warehouse_id' => 2, 'source_reference' => 'TR-A-B', 'quantity' => '10', 'value' => '100', 'business_date' => '2026-09-03', 'metadata' => ['transfer_event' => 'ACCEPT']]);
        $secondOut = $this->allocation(['warehouse_id' => 2, 'source_reference' => 'TR-B-C', 'direction' => 'OUT', 'quantity' => '4', 'value' => '-40', 'business_date' => '2026-09-04', 'metadata' => ['transfer_event' => 'DISPATCH']]);
        $this->allocation(['parent_allocation_id' => $secondOut, 'warehouse_id' => 3, 'source_reference' => 'TR-B-C', 'quantity' => '4', 'value' => '40', 'business_date' => '2026-09-05', 'metadata' => ['transfer_event' => 'ACCEPT']]);
        DB::table('wms_stock_balances')->insert(['warehouse_id' => 1, 'item_id' => 10, 'uom_id' => 20, 'on_hand' => 0, 'reserved' => 0, 'available' => 0, 'inventory_value' => 0, 'average_unit_cost' => 0]);
        DB::table('wms_stock_balances')->insert(['warehouse_id' => 2, 'item_id' => 10, 'uom_id' => 20, 'on_hand' => 6, 'reserved' => 0, 'available' => 6, 'inventory_value' => 60, 'average_unit_cost' => 10]);
        DB::table('wms_stock_balances')->insert(['warehouse_id' => 3, 'item_id' => 10, 'uom_id' => 20, 'on_hand' => 4, 'reserved' => 0, 'available' => 4, 'inventory_value' => 40, 'average_unit_cost' => 10]);

        $result = app(CostShadowCalculationService::class)->calculate($root, '12.00000000');

        $this->assertSame(2, $result['summary']['transfer_replay_partitions']);
        $this->assertSame(['20.00000000', '-8.00000000'], array_column($result['transfer_replays']['partitions'][0]['rows'], 'estimated_delta_value'));
        $this->assertSame(['8.00000000'], array_column($result['transfer_replays']['partitions'][1]['rows'], 'estimated_delta_value'));
        $this->assertSame('20.00000000', $result['recursive_impact_summary']['ending_on_hand_delta_value']);
        $this->assertSame('0.00000000', $result['recursive_impact_summary']['open_bridge_delta_value']);
        $this->assertSame('0.00000000', $result['recursive_impact_summary']['rounding_residual_value']);
        $this->assertTrue($result['transfer_replays']['read_only']);
    }

    public function test_shadow_stops_transfer_replay_at_the_total_node_budget(): void
    {
        $root = $this->allocation(['quantity' => '10', 'value' => '100', 'business_date' => '2026-09-01']);
        $out = $this->allocation(['parent_allocation_id' => $root, 'direction' => 'OUT', 'quantity' => '10', 'value' => '-100', 'business_date' => '2026-09-02', 'metadata' => ['transfer_event' => 'DISPATCH']]);
        $this->allocation(['parent_allocation_id' => $out, 'warehouse_id' => 2, 'quantity' => '10', 'value' => '100', 'business_date' => '2026-09-03', 'metadata' => ['transfer_event' => 'ACCEPT']]);

        $result = app(CostShadowCalculationService::class)->calculate($root, '12.00000000', 2);

        $this->assertSame(0, $result['summary']['transfer_replay_partitions']);
        $this->assertContains('TRANSFER_REPLAY_NODE_LIMIT_REACHED', $result['transfer_replays']['blockers']);
        $this->assertSame('REQUIRES_REVIEW', $result['variance_report']['status']);
    }

    public function test_shadow_replays_material_cost_into_finished_output_partition(): void
    {
        $root = $this->allocation(['source_reference' => 'RAW-RECEIPT', 'quantity' => '10', 'value' => '100', 'business_date' => '2026-09-01']);
        $issueId = DB::table('wms_issue_documents')->insertGetId(['warehouse_id' => 1, 'issue_type' => 'PRODUCTION', 'status' => 'POSTED', 'document_date' => '2026-09-02']);
        $material = $this->allocation(['source_type' => 'ISSUE_DOCUMENT', 'source_id' => (string) $issueId, 'source_reference' => 'MI-1', 'movement_type' => 'ISSUE', 'direction' => 'OUT', 'quantity' => '10', 'value' => '-100', 'business_date' => '2026-09-02', 'metadata' => ['issue_type' => 'PRODUCTION']]);
        $materialMovement = DB::table('wms_cost_allocations')->where('id', $material)->value('stock_movement_id');
        $issueLineId = DB::table('wms_issue_lines')->insertGetId(['document_id' => $issueId, 'stock_movement_id' => $materialMovement]);
        $receiptId = DB::table('wms_inventory_adjustment_documents')->insertGetId(['source_issue_id' => $issueId, 'document_context' => 'PRODUCTION_RECEIPT', 'status' => 'POSTED', 'document_date' => '2026-09-03']);
        DB::table('wms_production_receipt_sources')->insert(['receipt_document_id' => $receiptId, 'issue_document_id' => $issueId, 'issue_line_id' => $issueLineId, 'source_allocation_id' => $material, 'consumed_quantity' => 10, 'consumed_value' => 100, 'position' => 1]);
        $output = $this->allocation(['source_type' => 'WMS_PRODUCTION_RECEIPT', 'source_id' => (string) $receiptId, 'source_reference' => 'FGR-1', 'item_id' => 99, 'movement_type' => 'RECEIPT', 'quantity' => '2', 'unit_cost' => '50', 'value' => '100', 'business_date' => '2026-09-03']);
        DB::table('wms_inventory_adjustments')->insert(['document_id' => $receiptId, 'line_number' => 1, 'cost_allocation_id' => $output]);
        $transferOut = $this->allocation(['source_reference' => 'TR-FG', 'item_id' => 99, 'direction' => 'OUT', 'quantity' => '1', 'unit_cost' => '50', 'value' => '-50', 'business_date' => '2026-09-04', 'metadata' => ['transfer_event' => 'DISPATCH']]);
        $this->allocation(['parent_allocation_id' => $transferOut, 'warehouse_id' => 2, 'source_reference' => 'TR-FG', 'item_id' => 99, 'quantity' => '1', 'unit_cost' => '50', 'value' => '50', 'business_date' => '2026-09-05', 'metadata' => ['transfer_event' => 'ACCEPT']]);
        DB::table('wms_stock_balances')->insert(['warehouse_id' => 1, 'item_id' => 10, 'uom_id' => 20, 'on_hand' => 0, 'reserved' => 0, 'available' => 0, 'inventory_value' => 0, 'average_unit_cost' => 0]);
        DB::table('wms_stock_balances')->insert(['warehouse_id' => 1, 'item_id' => 99, 'uom_id' => 20, 'on_hand' => 1, 'reserved' => 0, 'available' => 1, 'inventory_value' => 50, 'average_unit_cost' => 50]);
        DB::table('wms_stock_balances')->insert(['warehouse_id' => 2, 'item_id' => 99, 'uom_id' => 20, 'on_hand' => 1, 'reserved' => 0, 'available' => 1, 'inventory_value' => 50, 'average_unit_cost' => 50]);

        $result = app(CostShadowCalculationService::class)->calculate($root, '12.00000000');

        $this->assertSame(1, $result['summary']['production_replay_partitions']);
        $this->assertSame(1, $result['summary']['transfer_replay_partitions']);
        $this->assertSame('20.00000000', $result['production_bridges']['edges'][0]['estimated_delta_value']);
        $this->assertSame('10.00000000', $result['production_replays']['partitions'][0]['impact_summary']['ending_on_hand_delta_value']);
        $this->assertSame('10.00000000', $result['transfer_replays']['partitions'][0]['impact_summary']['ending_on_hand_delta_value']);
        $this->assertSame('20.00000000', $result['recursive_impact_summary']['ending_on_hand_delta_value']);
        $this->assertSame('0.00000000', $result['recursive_impact_summary']['terminal_delta_value']);
        $this->assertSame('0.00000000', $result['recursive_impact_summary']['rounding_residual_value']);
    }

    private function allocation(array $overrides = []): int
    {
        $sourceType = $overrides['source_type'] ?? 'WMS_TRANSFER';
        $id = DB::table('wms_stock_movements')->insertGetId([
            'warehouse_id' => $overrides['warehouse_id'] ?? 1,
            'item_id' => $overrides['item_id'] ?? 10,
            'uom_id' => $overrides['uom_id'] ?? 20,
            'movement_type' => $overrides['movement_type'] ?? 'TRANSFER',
            'direction' => $overrides['direction'] ?? 'IN',
            'status' => 'POSTED',
            'quantity' => $overrides['quantity'] ?? 1,
            'base_quantity' => $overrides['quantity'] ?? 1,
            'business_date' => $overrides['business_date'] ?? '2026-09-08',
            'source_type' => $sourceType,
            'source_id' => $overrides['source_id'] ?? (string) microtime(true),
            'source_reference' => $overrides['source_reference'] ?? 'TEST',
            'metadata' => json_encode($overrides['metadata'] ?? []),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($sourceType === 'WMS_TRANSFER') {
            DB::table('wms_transfer_events')->insert([
                'stock_movement_id' => $id,
                'business_date' => $overrides['business_date'] ?? '2026-09-08',
            ]);
        }

        return DB::table('wms_cost_allocations')->insertGetId([
            'stock_movement_id' => $id,
            'stock_cost_layer_id' => $overrides['stock_cost_layer_id'] ?? null,
            'parent_allocation_id' => $overrides['parent_allocation_id'] ?? null,
            'warehouse_id' => $overrides['warehouse_id'] ?? 1,
            'item_id' => $overrides['item_id'] ?? 10,
            'uom_id' => $overrides['uom_id'] ?? 20,
            'allocation_type' => $overrides['allocation_type'] ?? 'TRANSFER',
            'direction' => $overrides['direction'] ?? 'IN',
            'cost_status' => 'FINAL',
            'status' => 'POSTED',
            'method' => $overrides['method'] ?? 'AVG',
            'policy_version' => 'test',
            'quantity' => $overrides['quantity'] ?? 1,
            'unit_cost' => $overrides['unit_cost'] ?? 10,
            'value' => $overrides['value'] ?? 10,
            'business_date' => $overrides['business_date'] ?? '2026-09-08',
            'idempotency_key' => 'lineage-test:'.uniqid('', true),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
