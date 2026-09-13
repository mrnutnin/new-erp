<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostRevaluationBatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CostPropagationTriggerDispatcher
{
    public function __construct(
        private readonly CostPropagationTriggerPlanner $planner,
        private readonly CostRevaluationApplyService $revaluations,
        private readonly CostRevaluationCompensationResolver $compensations,
    ) {}

    /** @param array<int,string> $proposedUnitCosts */
    public function dispatchIfEnabled(string $documentType, int $documentId, int $revision = 0, array $proposedUnitCosts = [], ?int $actorId = null): ?CostRevaluationBatch
    {
        return config('erp.inventory.revaluation_auto_trigger_enabled', false)
            ? $this->dispatch($documentType, $documentId, $revision, $proposedUnitCosts, $actorId)
            : null;
    }

    /**
     * Persist only a bounded plan and enqueue child workers after the source
     * transaction commits. Costs omitted by the caller keep their immutable
     * allocation value, which makes retries deterministic.
     *
     * @param  array<int,string>  $proposedUnitCosts
     */
    public function dispatch(string $documentType, int $documentId, int $revision = 0, array $proposedUnitCosts = [], ?int $actorId = null): CostRevaluationBatch
    {
        $plan = $this->planner->plan($documentType, $documentId, $revision);
        $allocationIds = collect($plan['partitions'])->flatMap(fn (array $partition): array => $partition['root_allocation_ids'])->unique()->values();
        $allocations = CostAllocation::query()->whereIn('id', $allocationIds->all())->get(['id', 'parent_allocation_id', 'revision', 'unit_cost', 'business_date'])->keyBy('id');
        if (collect(array_keys($proposedUnitCosts))->map(fn ($id): int => (int) $id)->diff($allocationIds)->isNotEmpty()) {
            throw ValidationException::withMessages(['allocation_id' => 'ต้นทุนที่ระบุมี Allocation ซึ่งไม่อยู่ในเอกสารต้นทาง']);
        }
        $compensation = $this->compensations->resolve($allocations->values(), (int) $plan['source']['revision']);
        $proposedUnitCosts = array_replace($proposedUnitCosts, $compensation['costs']);
        if ($compensation['blockers'] !== []) {
            $plan['blockers'] = array_values(array_unique([...$plan['blockers'], ...$compensation['blockers']]));
            $plan['summary']['ready'] = false;
        }
        $rootRevisions = $allocations->mapWithKeys(fn (CostAllocation $allocation): array => [(string) $allocation->id => (int) $allocation->revision])->sortKeys()->all();
        $resolvedCosts = $allocations->mapWithKeys(fn (CostAllocation $allocation): array => [(int) $allocation->id => (string) ($proposedUnitCosts[(int) $allocation->id] ?? $allocation->unit_cost)])->sortKeys()->all();
        $snapshot = ['source' => $plan['source'], 'summary' => $plan['summary'], 'root_revisions' => $rootRevisions, 'proposed_unit_costs' => $resolvedCosts, 'partitions' => collect($plan['partitions'])->map(fn (array $partition): array => ['partition_key' => $partition['partition_key'], 'root_line_ids' => $partition['root_line_ids'], 'root_allocation_ids' => $partition['root_allocation_ids']])->all()];
        if ($compensation['links'] !== []) {
            $snapshot['compensation'] = ['type' => 'REVERSAL_AFTER_REVALUATION', 'links' => $compensation['links']];
        }

        return DB::transaction(function () use ($plan, $snapshot, $resolvedCosts, $actorId): CostRevaluationBatch {
            $source = $plan['source'];
            $identity = (string) $source['trigger_identity'];
            $existing = CostRevaluationBatch::query()->where('idempotency_key', $identity)->first();
            if ($existing && $existing->runs()->where('status', '!=', 'CANCELLED')->doesntExist()) {
                $identity = hash('sha256', json_encode(['retry-after-cancel', $identity, (string) \Illuminate\Support\Str::uuid()], JSON_THROW_ON_ERROR));
            }
            $batch = CostRevaluationBatch::query()->firstOrCreate(['idempotency_key' => $identity], [
                'source_document_type' => $source['document_type'], 'source_document_id' => $source['document_id'],
                'source_document_reference' => $source['document_reference'], 'document_date' => $source['document_date'],
                'source_revision' => $source['revision'], 'status' => $plan['summary']['ready'] ? 'QUEUED' : 'REQUIRES_REVIEW',
                'expected_root_lines' => $plan['summary']['expected_root_lines'], 'resolved_root_lines' => $plan['summary']['resolved_root_lines'],
                'expected_partitions' => $plan['summary']['expected_partitions'], 'completed_partitions' => 0, 'failed_partitions' => 0,
                'blockers' => $plan['blockers'], 'trigger_snapshot' => $snapshot, 'requested_by' => $actorId,
            ]);
            if ($batch->trigger_snapshot !== $snapshot) {
                $batch->forceFill(['status' => 'REQUIRES_REVIEW', 'blockers' => ['SOURCE_REVISION_CHANGED']])->save();

                return $batch;
            }
            if (! $plan['summary']['ready']) {
                return $batch;
            }

            foreach ($plan['partitions'] as $partition) {
                $costs = collect($partition['root_allocation_ids'])->mapWithKeys(fn ($id): array => [(int) $id => $resolvedCosts[(int) $id]])->all();
                $this->revaluations->dispatchPartitionCalculation($costs, $actorId, $batch->id, $partition['partition_key'], $partition['child_identity']);
            }

            $batch->forceFill(['status' => 'CALCULATING', 'heartbeat_at' => now()])->save();

            return $batch->fresh('runs');
        }, 3);
    }
}
