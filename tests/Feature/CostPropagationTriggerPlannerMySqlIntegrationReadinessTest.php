<?php

namespace Tests\Feature;

use App\Modules\Wms\Jobs\CalculateCostRevaluation;
use App\Modules\Wms\Models\CostRevaluationBatch;
use App\Modules\Wms\Models\CostRevaluationDelta;
use App\Modules\Wms\Models\CostRevaluationRun;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Services\CostPropagationTriggerDispatcher;
use App\Modules\Wms\Services\CostPropagationTriggerPlanner;
use App\Modules\Wms\Services\CostRevaluationBatchService;
use App\Modules\Wms\Services\CostRevaluationCompensationResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class CostPropagationTriggerPlannerMySqlIntegrationReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
    }

    public function test_multi_line_document_plan_is_stable_complete_and_read_only(): void
    {
        $document = IssueDocument::withTrashed()->where('status', 'POSTED')
            ->whereHas('lines', fn ($query) => $query->whereNotNull('stock_movement_id'))
            ->with('lines')->withCount('lines')->orderByDesc('lines_count')->firstOrFail();
        $before = $this->ledgerCounts();

        $first = app(CostPropagationTriggerPlanner::class)->plan('ISSUE_DOCUMENT', $document->id);
        $retry = app(CostPropagationTriggerPlanner::class)->plan('ISSUE_DOCUMENT', $document->id);

        $this->assertTrue($first['read_only']);
        $this->assertFalse($first['dispatch']);
        $this->assertSame($document->lines->count(), $first['summary']['expected_root_lines']);
        $this->assertSame($first['source']['trigger_identity'], $retry['source']['trigger_identity']);
        $this->assertSame($first['partitions'], $retry['partitions']);
        $this->assertSame($before, $this->ledgerCounts());
    }

    public function test_document_dispatch_is_idempotent_and_enqueues_every_partition_after_commit(): void
    {
        Queue::fake();
        $document = IssueDocument::withTrashed()->where('status', 'POSTED')
            ->whereHas('lines', fn ($query) => $query->whereNotNull('stock_movement_id'))
            ->with('lines')->withCount('lines')->orderByDesc('lines_count')->firstOrFail();
        $before = $this->ledgerCounts();

        $first = app(CostPropagationTriggerDispatcher::class)->dispatch('ISSUE_DOCUMENT', $document->id);
        $retry = app(CostPropagationTriggerDispatcher::class)->dispatch('ISSUE_DOCUMENT', $document->id);

        $this->assertSame($first->id, $retry->id);
        $this->assertSame($first->expected_root_lines, $first->resolved_root_lines);
        $this->assertCount($first->trigger_snapshot['summary']['expected_partitions'], $first->runs);
        foreach ($first->runs as $run) {
            $expectedRoots = collect($first->trigger_snapshot['partitions'])->firstWhere('partition_key', $run->partition_key)['root_allocation_ids'];
            $actualRoots = collect(data_get($run->runtime_checkpoint, 'current_partition.root_overrides'))->pluck('allocation_id')->all();
            $this->assertSame($expectedRoots, $actualRoots);
            $run->forceFill(['status' => 'PENDING_APPROVAL', 'completed_partitions' => $run->expected_partitions])->save();
        }
        $synced = app(CostRevaluationBatchService::class)->sync($first->id);
        $this->assertSame('PENDING_APPROVAL', $synced->status);
        $this->assertSame($synced->expected_partitions, $synced->completed_partitions);
        Queue::assertPushed(CalculateCostRevaluation::class, $first->runs->count());

        DB::transaction(function () use ($first): void {
            $first->runs()->each(fn ($run) => $run->delete());
            CostRevaluationBatch::query()->whereKey($first->id)->delete();
        });
        $this->assertSame($before, $this->ledgerCounts());
    }

    public function test_rolled_back_source_transaction_leaves_no_batch_and_jobs_are_after_commit_only(): void
    {
        Queue::fake();
        $document = IssueDocument::withTrashed()->where('status', 'POSTED')
            ->whereHas('lines', fn ($query) => $query->whereNotNull('stock_movement_id'))->firstOrFail();
        $before = $this->ledgerCounts();

        DB::beginTransaction();
        app(CostPropagationTriggerDispatcher::class)->dispatch('ISSUE_DOCUMENT', $document->id, 991);
        DB::rollBack();

        Queue::assertNothingPushed();
        $this->assertSame($before, $this->ledgerCounts());
    }

    public function test_automatic_trigger_gate_is_closed_by_default(): void
    {
        Queue::fake();
        config()->set('erp.inventory.revaluation_auto_trigger_enabled', false);
        $document = IssueDocument::withTrashed()->where('status', 'POSTED')
            ->whereHas('lines', fn ($query) => $query->whereNotNull('stock_movement_id'))->firstOrFail();
        $before = $this->ledgerCounts();

        $batch = app(CostPropagationTriggerDispatcher::class)->dispatchIfEnabled('ISSUE_DOCUMENT', $document->id);

        $this->assertNull($batch);
        Queue::assertNothingPushed();
        $this->assertSame($before, $this->ledgerCounts());
    }

    public function test_reversal_dispatch_creates_idempotent_compensating_child_run(): void
    {
        $document = InventoryAdjustmentDocument::query()->where('status', 'REVERSED')
            ->whereHas('lines', fn ($query) => $query->whereNotNull('reversal_allocation_id'))
            ->with('lines')->first();
        $reversal = $document?->lines->firstWhere('reversal_allocation_id', '!=', null)?->reversalAllocation;
        $parent = $reversal?->parent;
        if (! $document || ! $reversal || ! $parent) {
            $this->markTestSkipped('ไม่มี reversed adjustment fixture สำหรับทดสอบ compensating dispatcher');
        }

        Queue::fake();
        $before = $this->ledgerCounts();
        DB::beginTransaction();
        try {
            $sourceRun = CostRevaluationRun::query()->create([
                'idempotency_key' => 'integration:dispatcher-compensation-source:'.bin2hex(random_bytes(8)),
                'root_allocation_id' => $parent->id, 'status' => 'COMPLETED',
                'proposed_unit_cost' => $parent->unit_cost, 'nodes_affected' => 1, 'nodes_scanned' => 1,
                'estimated_delta_value' => $parent->quantity,
                'shadow_snapshot' => ['calculation_contract_version' => 'cost-shadow-v2-avg-canonical-movement-quantity-legacy-proof', 'summary' => ['impact_date' => $parent->business_date->format('Y-m-d')]],
                'runtime_checkpoint' => ['stage' => 'APPLY'],
            ]);
            CostRevaluationDelta::query()->create([
                'run_id' => $sourceRun->id, 'allocation_id' => $parent->id,
                'applied_cost_allocation_id' => $reversal->id,
                'idempotency_key' => $sourceRun->idempotency_key.':allocation:'.$parent->id,
                'status' => 'GL_POSTED', 'old_unit_cost' => $parent->unit_cost, 'new_unit_cost' => $parent->unit_cost,
                'delta_value' => $parent->quantity, 'stock_projection_delta_value' => '0',
            ]);
            $expected = app(CostRevaluationCompensationResolver::class)->resolve(collect([$reversal]), 1);
            $revision = 900000 + random_int(1, 99999);
            $first = app(CostPropagationTriggerDispatcher::class)->dispatch('INVENTORY_ADJUSTMENT', $document->id, $revision);
            $retry = app(CostPropagationTriggerDispatcher::class)->dispatch('INVENTORY_ADJUSTMENT', $document->id, $revision);
            $override = collect(data_get($first->runs->first()->runtime_checkpoint, 'current_partition.root_overrides'))->firstWhere('allocation_id', $reversal->id);

            self::assertSame($first->id, $retry->id);
            self::assertSame('REVERSAL_AFTER_REVALUATION', data_get($first->trigger_snapshot, 'compensation.type'));
            self::assertContains($sourceRun->id, data_get($first->trigger_snapshot, 'compensation.links.0.source_run_ids'));
            self::assertSame($expected['costs'][$reversal->id], $override['proposed_unit_cost']);
            self::assertSame('COMPLETED', $sourceRun->fresh()->status);
            Queue::assertPushed(CalculateCostRevaluation::class, $first->runs->count());
        } finally {
            DB::rollBack();
        }
        self::assertSame($before, $this->ledgerCounts());
    }

    /** @return array<string, int> */
    private function ledgerCounts(): array
    {
        return collect(['wms_stock_movements', 'wms_cost_allocations', 'wms_cost_revaluation_batches', 'wms_cost_revaluation_runs', 'wms_cost_revaluation_deltas'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
