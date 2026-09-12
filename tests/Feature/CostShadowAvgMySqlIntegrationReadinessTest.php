<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Wms\Jobs\CalculateCostRevaluation;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostRevaluationBatch;
use App\Modules\Wms\Models\CostRevaluationDelta;
use App\Modules\Wms\Models\CostRevaluationRun;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Services\CostPropagationManualTriggerService;
use App\Modules\Wms\Services\CostPropagationScopeTriggerService;
use App\Modules\Wms\Services\CostRevaluationApplyService;
use App\Modules\Wms\Services\CostRevaluationCompensationResolver;
use App\Modules\Wms\Services\CostRevaluationRecoveryService;
use App\Modules\Wms\Services\CostShadowCalculationService;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class CostShadowAvgMySqlIntegrationReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }
    }

    public function test_backdated_adjustment_reaches_later_material_issue_without_writes(): void
    {
        $root = DB::table('wms_inventory_adjustment_documents as documents')
            ->join('wms_inventory_adjustments as lines', 'lines.document_id', '=', 'documents.id')
            ->join('wms_cost_allocations as allocations', 'allocations.id', '=', 'lines.cost_allocation_id')
            ->where('documents.document_number', 'ADJHQ2609000005')
            ->where('allocations.method', 'AVG')
            ->orderBy('allocations.id')
            ->first(['allocations.id', 'allocations.unit_cost']);
        if (! $root || ! DB::table('wms_issue_documents')->where('document_number', 'ISSUEHQ2609000004')->exists()) {
            $this->markTestSkipped('Development fixture ADJHQ2609000005 → ISSUEHQ2609000004 ยังไม่มีในฐานข้อมูลนี้');
        }

        $before = $this->ledgerCounts();
        $proposed = BigDecimal::of((string) $root->unit_cost)->plus('1')->__toString();
        $result = app(CostShadowCalculationService::class)->calculate((int) $root->id, $proposed);
        $issue = collect($result['rows'])->firstWhere('source_reference', 'ISSUEHQ2609000004');

        self::assertSame('cost-shadow-v2-avg-canonical-movement-quantity-legacy-proof', $result['calculation_contract_version']);
        self::assertNotNull($issue);
        self::assertSame('2026-09-07', $issue['business_date']);
        self::assertSame('WIP_CONSUMED', $issue['impact_bucket']);
        self::assertNotSame('0.00000000', $issue['estimated_delta_value']);
        self::assertNotContains('ALLOCATION_NOT_POSTED', $result['variance_report']['blockers']);
        self::assertSame($before, $this->ledgerCounts());
    }

    public function test_canonical_shadow_blocks_legacy_unlinked_issue_without_leaking_test_data(): void
    {
        $root = DB::table('wms_inventory_adjustment_documents as documents')
            ->join('wms_inventory_adjustments as lines', 'lines.document_id', '=', 'documents.id')
            ->join('wms_cost_allocations as allocations', 'allocations.id', '=', 'lines.cost_allocation_id')
            ->where('documents.document_number', 'ADJHQ2609000005')->where('allocations.method', 'AVG')
            ->orderBy('allocations.id')->first(['allocations.id', 'allocations.unit_cost']);
        $actor = User::query()->first();
        if (! $root || ! $actor) {
            $this->markTestSkipped('Development fixture ADJHQ2609000005 หรือผู้ใช้งานยังไม่มี');
        }

        $before = $this->ledgerCounts();
        $blocked = false;
        try {
            $proposed = BigDecimal::of((string) $root->unit_cost)->plus('0.31415926')->__toString();
            $shadow = app(CostShadowCalculationService::class)->calculate((int) $root->id, $proposed);
            self::assertTrue(collect($shadow['rows'])->where('source_type', 'ISSUE_DOCUMENT')->contains(fn (array $row): bool => in_array('ACCOUNTING_PROOF_PENDING', $row['issues'], true)));
            self::assertFalse(collect($shadow['rows'])->where('source_type', 'WMS_TRANSFER')->contains(fn (array $row): bool => in_array('ACCOUNTING_PROOF_PENDING', $row['issues'], true)));
            app(CostRevaluationApplyService::class)->queue((int) $root->id, $proposed, (int) $actor->id);
        } catch (ValidationException) {
            $blocked = true;
        }
        self::assertTrue($blocked, 'Legacy Issue ที่ไม่มี immutable Journal proof ต้อง block Apply Run');
        self::assertSame($before, $this->ledgerCounts());
    }

    public function test_queued_calculation_persists_bounded_checkpoint_without_leaking_test_data(): void
    {
        $root = DB::table('wms_inventory_adjustment_documents as documents')
            ->join('wms_inventory_adjustments as lines', 'lines.document_id', '=', 'documents.id')
            ->join('wms_cost_allocations as allocations', 'allocations.id', '=', 'lines.cost_allocation_id')
            ->where('documents.document_number', 'ADJHQ2609000005')->where('allocations.method', 'AVG')
            ->orderBy('allocations.id')->first(['allocations.id', 'allocations.revision', 'allocations.unit_cost']);
        if (! $root) {
            $this->markTestSkipped('Development fixture ADJHQ2609000005 ยังไม่มี');
        }

        $before = $this->ledgerCounts();
        $oldChunkSize = config('erp.inventory.revaluation_chunk_size');
        config(['erp.inventory.revaluation_chunk_size' => 1]);
        DB::beginTransaction();
        try {
            $run = CostRevaluationRun::query()->create([
                'idempotency_key' => 'integration:queued-shadow:'.bin2hex(random_bytes(8)),
                'root_allocation_id' => $root->id, 'status' => 'QUEUED',
                'proposed_unit_cost' => BigDecimal::of((string) $root->unit_cost)->plus('0.125')->__toString(),
                'nodes_affected' => 0, 'nodes_scanned' => 0, 'estimated_delta_value' => 0,
                'shadow_snapshot' => ['calculation_contract_version' => 'PENDING'],
                'runtime_checkpoint' => ['stage' => 'CALCULATION', 'source_revision' => (int) $root->revision],
            ]);

            $calculated = app(CostRevaluationApplyService::class)->calculateQueued($run);

            self::assertSame('WAITING_CONTINUATION', $calculated->status);
            self::assertSame(1, $calculated->deltas()->count());
            self::assertNotNull(data_get($calculated->runtime_checkpoint, 'current_partition.checkpoint.cursor'));
            for ($attempt = 0; $attempt < 100 && $calculated->status === 'WAITING_CONTINUATION'; $attempt++) {
                $calculated = app(CostRevaluationApplyService::class)->calculateQueued($calculated);
            }
            self::assertContains($calculated->status, ['PENDING_APPROVAL', 'REQUIRES_REVIEW', 'LIMIT_REACHED']);
            self::assertStringStartsWith('cost-shadow-v2-', $calculated->shadow_snapshot['calculation_contract_version']);
            self::assertSame('CALCULATION', $calculated->runtime_checkpoint['stage']);
            self::assertGreaterThan(0, $calculated->nodes_scanned);
            self::assertNotNull($calculated->heartbeat_at);
        } finally {
            DB::rollBack();
            config(['erp.inventory.revaluation_chunk_size' => $oldChunkSize]);
        }
        self::assertSame($before, $this->ledgerCounts());
    }

    public function test_partition_chunk_resumes_after_keyset_cursor_without_replaying_rows(): void
    {
        $root = DB::table('wms_inventory_adjustment_documents as documents')
            ->join('wms_inventory_adjustments as lines', 'lines.document_id', '=', 'documents.id')
            ->join('wms_cost_allocations as allocations', 'allocations.id', '=', 'lines.cost_allocation_id')
            ->where('documents.document_number', 'ADJHQ2609000005')->where('allocations.method', 'AVG')
            ->orderBy('allocations.id')->first(['allocations.id', 'allocations.unit_cost']);
        if (! $root) {
            $this->markTestSkipped('Development fixture ADJHQ2609000005 ยังไม่มี');
        }

        $before = $this->ledgerCounts();
        $proposed = BigDecimal::of((string) $root->unit_cost)->plus('0.125')->__toString();
        $first = app(CostShadowCalculationService::class)->calculatePartitionChunk((int) $root->id, $proposed, null, 1);
        if ($first['next_checkpoint'] === null) {
            $this->markTestSkipped('Fixture ไม่มี timeline node ถัดไปสำหรับทดสอบ keyset continuation');
        }
        $second = app(CostShadowCalculationService::class)->calculatePartitionChunk((int) $root->id, $proposed, $first['next_checkpoint'], 1);

        self::assertNotSame($first['rows'][0]['allocation_id'], $second['rows'][0]['allocation_id']);
        self::assertSame(2, $second['summary']['nodes_scanned_total']);
        self::assertSame($before, $this->ledgerCounts());
    }

    public function test_active_calculation_lease_prevents_a_second_worker_from_claiming_the_run(): void
    {
        $root = DB::table('wms_cost_allocations')->where('status', 'POSTED')->orderBy('id')->first(['id', 'revision', 'unit_cost']);
        if (! $root) {
            $this->markTestSkipped('ไม่มี Posted Cost Allocation สำหรับทดสอบ lease');
        }

        DB::beginTransaction();
        try {
            $run = CostRevaluationRun::query()->create([
                'idempotency_key' => 'integration:active-lease:'.bin2hex(random_bytes(8)),
                'root_allocation_id' => $root->id, 'status' => 'CALCULATING',
                'proposed_unit_cost' => (string) $root->unit_cost,
                'nodes_affected' => 0, 'nodes_scanned' => 0, 'estimated_delta_value' => 0,
                'shadow_snapshot' => ['calculation_contract_version' => 'PENDING'],
                'runtime_checkpoint' => [
                    'stage' => 'CALCULATION', 'source_revision' => (int) $root->revision,
                    'calculation_lease' => ['token' => 'worker-one', 'claimed_at' => now()->toIso8601String()],
                ],
                'heartbeat_at' => now(),
            ]);

            $claimed = app(CostRevaluationApplyService::class)->calculateQueued($run);

            self::assertSame('CALCULATING', $claimed->status);
            self::assertSame('worker-one', data_get($claimed->runtime_checkpoint, 'calculation_lease.token'));
            self::assertSame(0, $claimed->deltas()->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_expired_lease_is_reclaimed_and_stale_root_revision_stops_before_calculation(): void
    {
        $root = DB::table('wms_cost_allocations')->where('status', 'POSTED')->orderBy('id')->first(['id', 'revision', 'unit_cost']);
        if (! $root) {
            $this->markTestSkipped('ไม่มี Posted Cost Allocation สำหรับทดสอบ stale revision');
        }

        DB::beginTransaction();
        try {
            $run = CostRevaluationRun::query()->create([
                'idempotency_key' => 'integration:expired-lease:'.bin2hex(random_bytes(8)),
                'root_allocation_id' => $root->id, 'status' => 'CALCULATING',
                'proposed_unit_cost' => (string) $root->unit_cost,
                'nodes_affected' => 0, 'nodes_scanned' => 0, 'estimated_delta_value' => 0,
                'shadow_snapshot' => ['calculation_contract_version' => 'PENDING'],
                'runtime_checkpoint' => [
                    'stage' => 'CALCULATION', 'source_revision' => (int) $root->revision + 1,
                    'calculation_lease' => ['token' => 'expired-worker', 'claimed_at' => now()->subMinutes(10)->toIso8601String()],
                ],
                'heartbeat_at' => now()->subMinutes(10),
            ]);

            $stopped = app(CostRevaluationApplyService::class)->calculateQueued($run);

            self::assertSame('REQUIRES_REVIEW', $stopped->status);
            self::assertContains('STALE_ROOT_REVISION', $stopped->runtime_checkpoint['blockers']);
            self::assertNull(data_get($stopped->runtime_checkpoint, 'calculation_lease'));
            self::assertSame(0, $stopped->nodes_scanned);
            self::assertSame(0, $stopped->deltas()->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_failed_calculation_can_be_resumed_and_is_audited(): void
    {
        $root = DB::table('wms_cost_allocations')->where('status', 'POSTED')->orderBy('id')->first(['id', 'revision', 'unit_cost']);
        $actor = User::query()->first();
        if (! $root || ! $actor) {
            $this->markTestSkipped('ไม่มี Posted Cost Allocation หรือ User สำหรับทดสอบ Recovery');
        }

        Queue::fake();
        $run = CostRevaluationRun::query()->create([
            'idempotency_key' => 'integration:resume:'.bin2hex(random_bytes(8)),
            'root_allocation_id' => $root->id, 'status' => 'FAILED_RETRYABLE', 'proposed_unit_cost' => (string) $root->unit_cost,
            'nodes_affected' => 0, 'nodes_scanned' => 0, 'estimated_delta_value' => 0,
            'shadow_snapshot' => ['calculation_contract_version' => 'PENDING'],
            'runtime_checkpoint' => ['stage' => 'CALCULATION', 'source_revision' => (int) $root->revision],
            'last_error' => 'temporary worker failure',
        ]);
        try {
            $resumed = app(CostRevaluationRecoveryService::class)->resume($run, $actor, 'ทดสอบส่งงานคำนวณกลับเข้าคิวใหม่');

            self::assertSame('WAITING_CONTINUATION', $resumed->status);
            self::assertNull($resumed->last_error);
            Queue::assertPushed(CalculateCostRevaluation::class, fn ($job): bool => $job->runId === $run->id);
            self::assertTrue(DB::table('audit_logs')->where('subject_type', $run->getMorphClass())->where('subject_id', $run->id)->where('action', 'wms.cost_revaluation.resumed')->exists());
        } finally {
            DB::table('audit_logs')->where('subject_type', $run->getMorphClass())->where('subject_id', $run->id)->delete();
            $run->delete();
        }
    }

    public function test_unapplied_run_can_be_cancelled_but_applied_delta_blocks_cancellation(): void
    {
        $root = DB::table('wms_cost_allocations')->where('status', 'POSTED')->orderBy('id')->first(['id', 'revision', 'unit_cost', 'warehouse_id']);
        $actor = User::query()->first();
        $branchId = $root ? DB::table('warehouses')->where('id', $root->warehouse_id)->value('branch_id') : null;
        if (! $root || ! $actor || ! $branchId) {
            $this->markTestSkipped('Fixture สำหรับทดสอบ Cancel ยังไม่พร้อม');
        }

        $run = CostRevaluationRun::query()->create([
            'idempotency_key' => 'integration:cancel:'.bin2hex(random_bytes(8)),
            'root_allocation_id' => $root->id, 'status' => 'FAILED_RETRYABLE', 'proposed_unit_cost' => (string) $root->unit_cost,
            'nodes_affected' => 0, 'nodes_scanned' => 0, 'estimated_delta_value' => 0,
            'shadow_snapshot' => ['calculation_contract_version' => 'PENDING'],
            'runtime_checkpoint' => ['stage' => 'CALCULATION', 'source_revision' => (int) $root->revision, 'calculation_lease' => ['token' => 'old-worker']],
        ]);
        try {
            $cancelled = app(CostRevaluationRecoveryService::class)->cancel($run, $actor, 'ทดสอบยกเลิกงานที่ยังไม่กระทบต้นทุน');
            self::assertSame('CANCELLED', $cancelled->status);
            self::assertNull(data_get($cancelled->runtime_checkpoint, 'calculation_lease'));

            $run->forceFill(['status' => 'FAILED_RETRYABLE', 'runtime_checkpoint' => ['stage' => 'APPLY']])->save();
            CostRevaluationDelta::query()->create([
                'run_id' => $run->id, 'allocation_id' => $root->id, 'idempotency_key' => $run->idempotency_key.':applied',
                'status' => 'APPLIED', 'old_unit_cost' => (string) $root->unit_cost, 'new_unit_cost' => (string) $root->unit_cost,
                'delta_value' => '0', 'impact_bucket' => 'INVENTORY_ON_HAND', 'target_event' => 'inventory.recost',
                'target_warehouse_id' => $root->warehouse_id, 'target_branch_id' => $branchId, 'stock_projection_delta_value' => '0',
            ]);
            $blocked = false;
            try {
                app(CostRevaluationRecoveryService::class)->cancel($run, $actor, 'ทดสอบห้ามยกเลิกหลังเริ่มกระทบต้นทุน');
            } catch (ValidationException) {
                $blocked = true;
            }
            self::assertTrue($blocked);
        } finally {
            DB::table('audit_logs')->where('subject_type', $run->getMorphClass())->where('subject_id', $run->id)->delete();
            CostRevaluationDelta::query()->where('run_id', $run->id)->delete();
            $run->delete();
        }
    }

    public function test_manual_document_trigger_previews_every_root_and_reuses_the_same_batch(): void
    {
        $document = InventoryAdjustmentDocument::query()->where('document_context', 'INVENTORY_ADJUSTMENT')
            ->whereIn('status', ['POSTED', 'REVERSED'])->whereHas('lines', fn ($query) => $query->whereNotNull('cost_allocation_id'))
            ->latest('id')->first();
        $actor = User::query()->first();
        if (! $document || ! $actor) {
            $this->markTestSkipped('ไม่มี Posted Inventory Adjustment หรือ User สำหรับทดสอบ Manual Trigger');
        }

        Queue::fake();
        DB::beginTransaction();
        try {
            $manual = app(CostPropagationManualTriggerService::class);
            $options = $manual->options('INVENTORY_ADJUSTMENT', (int) $document->warehouse_id, (string) $document->document_number);
            $preview = $manual->preview('INVENTORY_ADJUSTMENT', (int) $document->id, (int) $document->warehouse_id);
            if (! $preview['summary']['ready']) {
                $this->markTestSkipped('Development fixture มี Trigger blocker: '.implode(', ', $preview['blockers']));
            }

            self::assertContains((int) $document->id, collect($options)->pluck('id')->all());
            self::assertSame($preview['summary']['expected_root_lines'], $preview['summary']['resolved_root_lines']);
            self::assertCount($preview['summary']['root_allocations'], $preview['root_costs']);

            $first = $manual->trigger('INVENTORY_ADJUSTMENT', (int) $document->id, (int) $document->warehouse_id, $actor, 'ทดสอบ Manual Trigger จากเอกสารครั้งแรก');
            $second = $manual->trigger('INVENTORY_ADJUSTMENT', (int) $document->id, (int) $document->warehouse_id, $actor, 'ทดสอบกด Manual Trigger ซ้ำเอกสารเดิม');

            self::assertSame($first->id, $second->id);
            self::assertSame($preview['summary']['expected_partitions'], $second->runs()->count());
            self::assertSame(2, DB::table('audit_logs')->where('subject_type', $second->getMorphClass())->where('subject_id', $second->id)->where('action', 'wms.cost_revaluation.manual_triggered')->count());
        } finally {
            DB::rollBack();
        }
    }

    public function test_manual_scope_trigger_plans_one_selected_item_in_bounded_partitions(): void
    {
        $actor = User::query()->whereHas('warehouses')->first();
        $warehouse = $actor?->warehouses()->where('warehouses.is_active', true)->first();
        $allocation = $warehouse ? CostAllocation::query()->where('warehouse_id', $warehouse->id)->where('status', '!=', 'REVERSED')->orderBy('business_date')->orderBy('id')->first() : null;
        if (! $actor || ! $warehouse || ! $allocation) {
            $this->markTestSkipped('ไม่มี User/Warehouse/Cost Allocation สำหรับทดสอบ Scope Trigger');
        }

        Queue::fake();
        DB::beginTransaction();
        try {
            $service = app(CostPropagationScopeTriggerService::class);
            $preview = $service->preview($actor, (int) $warehouse->branch_id, 'SELECTED', [(int) $warehouse->id], 'SELECTED', [(int) $allocation->item_id], $allocation->business_date->format('Y-m-d'));
            self::assertTrue($preview['summary']['ready']);
            self::assertGreaterThan(0, $preview['summary']['partitions']);
            self::assertNotNull($preview['availability']['last_date']);

            $batch = CostRevaluationBatch::query()->create([
                'idempotency_key' => 'integration:manual-scope:'.bin2hex(random_bytes(8)),
                'source_document_type' => 'MANUAL_SCOPE', 'source_document_id' => $preview['scope']['horizon_allocation_id'],
                'source_document_reference' => 'TEST SCOPE', 'document_date' => $preview['scope']['start_date'], 'source_revision' => 0,
                'status' => 'PLANNING', 'expected_root_lines' => $preview['summary']['partitions'], 'resolved_root_lines' => 0,
                'expected_partitions' => $preview['summary']['partitions'], 'completed_partitions' => 0, 'failed_partitions' => 0,
                'blockers' => [], 'trigger_snapshot' => ['scope' => [...$preview['scope'], 'cursor' => null, 'planning_complete' => false, 'dispatched_partitions' => 0]],
                'requested_by' => $actor->id, 'heartbeat_at' => now(),
            ]);

            $hasMore = $service->dispatchChunk($batch->id);
            self::assertFalse($hasMore);
            self::assertSame($preview['summary']['partitions'], $batch->runs()->count());
            self::assertTrue((bool) data_get($batch->fresh()->trigger_snapshot, 'scope.planning_complete'));
        } finally {
            DB::rollBack();
        }
    }

    public function test_reversal_uses_effective_cost_from_applied_revaluation_without_mutating_original_run(): void
    {
        $reversal = CostAllocation::query()->whereNotNull('parent_allocation_id')->where('status', '!=', 'REVERSED')->orderByDesc('id')->first();
        $parent = $reversal?->parent;
        if (! $reversal || ! $parent || BigDecimal::of((string) $parent->quantity)->isLessThanOrEqualTo(0)) {
            $this->markTestSkipped('ไม่มี reversal allocation ที่มี parent สำหรับทดสอบ compensating run');
        }

        $before = $this->ledgerCounts();
        DB::beginTransaction();
        try {
            $run = CostRevaluationRun::query()->create([
                'idempotency_key' => 'integration:compensation-source:'.bin2hex(random_bytes(8)),
                'root_allocation_id' => $parent->id, 'status' => 'COMPLETED',
                'proposed_unit_cost' => BigDecimal::of((string) $parent->unit_cost)->plus('1')->__toString(),
                'nodes_affected' => 1, 'nodes_scanned' => 1, 'estimated_delta_value' => $parent->quantity,
                'shadow_snapshot' => ['calculation_contract_version' => 'cost-shadow-v2-avg', 'summary' => ['impact_date' => $parent->business_date->format('Y-m-d')]],
                'runtime_checkpoint' => ['stage' => 'APPLY'],
            ]);
            CostRevaluationDelta::query()->create([
                'run_id' => $run->id, 'allocation_id' => $parent->id,
                'applied_cost_allocation_id' => $reversal->id,
                'idempotency_key' => $run->idempotency_key.':allocation:'.$parent->id,
                'status' => 'GL_POSTED', 'old_unit_cost' => $parent->unit_cost,
                'new_unit_cost' => BigDecimal::of((string) $parent->unit_cost)->plus('1')->__toString(),
                'delta_value' => $parent->quantity, 'stock_projection_delta_value' => '0',
            ]);
            $futureRun = CostRevaluationRun::query()->create([
                'idempotency_key' => 'integration:future-compensation-source:'.bin2hex(random_bytes(8)),
                'root_allocation_id' => $parent->id, 'status' => 'COMPLETED',
                'proposed_unit_cost' => BigDecimal::of((string) $parent->unit_cost)->plus('99')->__toString(),
                'nodes_affected' => 1, 'nodes_scanned' => 1, 'estimated_delta_value' => $parent->quantity,
                'shadow_snapshot' => ['calculation_contract_version' => 'cost-shadow-v2-avg', 'summary' => ['impact_date' => $reversal->business_date->addDay()->format('Y-m-d')]],
                'runtime_checkpoint' => ['stage' => 'APPLY'],
            ]);
            CostRevaluationDelta::query()->create([
                'run_id' => $futureRun->id, 'allocation_id' => $parent->id,
                'applied_cost_allocation_id' => $reversal->id,
                'idempotency_key' => $futureRun->idempotency_key.':allocation:'.$parent->id,
                'status' => 'GL_POSTED', 'old_unit_cost' => $parent->unit_cost,
                'new_unit_cost' => BigDecimal::of((string) $parent->unit_cost)->plus('99')->__toString(),
                'delta_value' => BigDecimal::of((string) $parent->quantity)->multipliedBy('99')->__toString(),
                'stock_projection_delta_value' => '0',
            ]);

            $result = app(CostRevaluationCompensationResolver::class)->resolve(collect([$reversal]), 1);

            self::assertSame(BigDecimal::of((string) $parent->unit_cost)->plus('1')->toScale(8)->__toString(), $result['costs'][$reversal->id]);
            self::assertSame([$run->id], $result['links'][0]['source_run_ids']);
            self::assertSame('COMPLETED', $run->fresh()->status);
            self::assertSame([], $result['blockers']);
        } finally {
            DB::rollBack();
        }
        self::assertSame($before, $this->ledgerCounts());
    }

    /** @return array<string, int> */
    private function ledgerCounts(): array
    {
        return collect(['wms_stock_movements', 'wms_cost_allocations', 'wms_cost_revaluation_runs', 'wms_cost_revaluation_deltas'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
