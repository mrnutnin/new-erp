<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Jobs\CalculateCostRevaluation;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostRevaluationDelta;
use App\Modules\Wms\Models\CostRevaluationRun;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Support\CostImpactBucket;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 3 boundary. It persists a reviewable, immutable Shadow plan only.
 * Applying stock/allocation/journal changes is deliberately a later gate.
 */
final class CostRevaluationApplyService
{
    public function dispatchCalculation(int $allocationId, string $proposedUnitCost, ?int $actorId = null): CostRevaluationRun
    {
        return $this->dispatchPartitionCalculation([$allocationId => $proposedUnitCost], $actorId);
    }

    /** @param array<int,string> $proposedUnitCosts */
    public function dispatchPartitionCalculation(array $proposedUnitCosts, ?int $actorId = null, ?int $batchId = null, ?string $partitionKey = null, ?string $idempotencyKey = null): CostRevaluationRun
    {
        ksort($proposedUnitCosts, SORT_NUMERIC);
        foreach ($proposedUnitCosts as $allocationId => $cost) {
            if ((int) $allocationId < 1 || ! preg_match('/^\d+(?:\.\d{1,8})?$/', (string) $cost)) {
                throw ValidationException::withMessages(['proposed_unit_cost' => 'ต้นทุนใหม่ต้องเป็นเลขทศนิยมไม่ติดลบ สูงสุด 8 ตำแหน่ง']);
            }
        }
        if ($proposedUnitCosts === []) {
            throw ValidationException::withMessages(['allocation_id' => 'ต้องมี Root Allocation อย่างน้อยหนึ่งรายการ']);
        }
        $sources = CostAllocation::query()->whereIn('id', array_keys($proposedUnitCosts))->get(['id', 'revision']);
        if ($sources->count() !== count($proposedUnitCosts)) {
            throw ValidationException::withMessages(['allocation_id' => 'ไม่พบ Root Allocation บางรายการ']);
        }
        $sourceRevisions = $sources->mapWithKeys(fn (CostAllocation $source): array => [(int) $source->id => (int) $source->revision])->sortKeys()->all();
        $allocationId = (int) array_key_first($proposedUnitCosts);
        $proposedUnitCost = (string) $proposedUnitCosts[$allocationId];
        $key = $idempotencyKey ?: hash('sha256', json_encode(['cost-shadow-v2', $sourceRevisions, $proposedUnitCosts], JSON_THROW_ON_ERROR));
        $rootOverrides = collect($proposedUnitCosts)->map(fn (string $cost, int $id): array => ['allocation_id' => $id, 'proposed_unit_cost' => $cost])->values()->all();
        $run = CostRevaluationRun::query()->firstOrCreate(['idempotency_key' => $key], [
            'batch_id' => $batchId,
            'partition_key' => $partitionKey,
            'root_allocation_id' => $allocationId,
            'status' => 'QUEUED',
            'proposed_unit_cost' => $proposedUnitCost,
            'nodes_affected' => 0,
            'nodes_scanned' => 0,
            'expected_partitions' => 1,
            'completed_partitions' => 0,
            'failed_partitions' => 0,
            'estimated_delta_value' => 0,
            'shadow_snapshot' => ['calculation_contract_version' => 'PENDING'],
            'runtime_checkpoint' => [
                'stage' => 'CALCULATION',
                'source_revision' => (int) $sourceRevisions[$allocationId],
                'source_revisions' => $sourceRevisions,
                'current_partition' => ['allocation_id' => $allocationId, 'proposed_unit_cost' => $proposedUnitCost, 'root_overrides' => $rootOverrides, 'depth' => 0, 'path' => array_keys($proposedUnitCosts), 'checkpoint' => null],
                'pending_partitions' => [],
                'seen_partition_roots' => [$allocationId],
                'blockers' => [],
            ],
            'requested_by' => $actorId,
        ]);

        if ($run->wasRecentlyCreated) {
            CalculateCostRevaluation::dispatch($run->id)->afterCommit();
        }

        return $run;
    }

    public function calculateQueued(CostRevaluationRun $run): CostRevaluationRun
    {
        [$run, $leaseToken] = DB::transaction(function () use ($run): array {
            $locked = CostRevaluationRun::query()->lockForUpdate()->findOrFail($run->id);
            $runtime = is_array($locked->runtime_checkpoint) ? $locked->runtime_checkpoint : [];
            $leaseSeconds = max(90, min((int) config('erp.inventory.revaluation_calculation_lease_seconds', 120), 600));
            $leaseActive = $locked->status === 'CALCULATING'
                && $locked->heartbeat_at?->gt(now()->subSeconds($leaseSeconds));
            $wrongStage = in_array($locked->status, ['WAITING_CONTINUATION', 'FAILED_RETRYABLE'], true)
                && ($runtime['stage'] ?? null) !== 'CALCULATION';
            if ($leaseActive || $wrongStage || ! in_array($locked->status, ['QUEUED', 'WAITING_CONTINUATION', 'FAILED_RETRYABLE', 'CALCULATING'], true)) {
                return [$locked, null];
            }
            $leaseToken = (string) Str::uuid();
            $runtime['calculation_lease'] = ['token' => $leaseToken, 'claimed_at' => now()->toIso8601String()];
            $locked->forceFill(['status' => 'CALCULATING', 'runtime_checkpoint' => $runtime, 'heartbeat_at' => now(), 'last_error' => null])->save();

            return [$locked, $leaseToken];
        }, 3);
        if ($leaseToken === null) {
            return $run;
        }

        try {
            $runtime = is_array($run->runtime_checkpoint) ? $run->runtime_checkpoint : [];
            if (! $this->rootRevisionsAreCurrent($run, $runtime)) {
                return $this->stopStaleCalculation($run, $leaseToken);
            }
            $partition = (array) ($runtime['current_partition'] ?? [
                'allocation_id' => (int) $run->root_allocation_id,
                'proposed_unit_cost' => (string) $run->proposed_unit_cost,
                'root_overrides' => [[
                    'allocation_id' => (int) $run->root_allocation_id,
                    'proposed_unit_cost' => (string) $run->proposed_unit_cost,
                ]],
                'depth' => 0,
                'path' => [(int) $run->root_allocation_id],
                'checkpoint' => null,
            ]);
            $nodeBudget = max(1, (int) config('erp.inventory.revaluation_calculation_node_budget', 1000));
            $remainingNodeBudget = $nodeBudget - (int) $run->nodes_scanned;
            if ($remainingNodeBudget < 1) {
                unset($runtime['calculation_lease']);
                $run->forceFill([
                    'status' => 'LIMIT_REACHED',
                    'failed_partitions' => (int) $run->failed_partitions + 1,
                    'runtime_checkpoint' => [...$runtime, 'blockers' => ['NODE_LIMIT_REACHED']],
                    'heartbeat_at' => now(),
                ])->save();

                return $run;
            }
            $limit = max(1, min((int) config('erp.inventory.revaluation_chunk_size', 250), $remainingNodeBudget, 1000));
            $chunk = app(CostShadowCalculationService::class)->calculatePartitionChunk(
                (int) ($partition['allocation_id'] ?? $run->root_allocation_id),
                (string) ($partition['proposed_unit_cost'] ?? $run->proposed_unit_cost),
                is_array($partition['checkpoint'] ?? null) ? $partition['checkpoint'] : null,
                $limit,
                (array) ($partition['root_overrides'] ?? []),
            );

            return DB::transaction(function () use ($run, $chunk, $partition, $leaseToken): CostRevaluationRun {
                $locked = CostRevaluationRun::query()->lockForUpdate()->findOrFail($run->id);
                $runtime = is_array($locked->runtime_checkpoint) ? $locked->runtime_checkpoint : [];
                if (data_get($runtime, 'calculation_lease.token') !== $leaseToken) {
                    return $locked;
                }
                if (! $this->rootRevisionsAreCurrent($locked, $runtime)) {
                    return $this->markStale($locked, $runtime);
                }
                $blockers = array_values(array_unique([...(array) ($runtime['blockers'] ?? []), ...(array) $chunk['blockers']]));
                if ($blockers !== []) {
                    $limited = collect($blockers)->contains(fn (string $blocker): bool => str_contains($blocker, 'LIMIT_'));
                    unset($runtime['calculation_lease']);
                    $locked->forceFill([
                        'status' => $limited ? 'LIMIT_REACHED' : 'REQUIRES_REVIEW',
                        'failed_partitions' => (int) $locked->failed_partitions + 1,
                        'runtime_checkpoint' => [...$runtime, 'blockers' => $blockers],
                        'heartbeat_at' => now(),
                    ])->save();

                    return $locked;
                }
                $this->persistChunkRows($locked, $chunk['rows']);
                [$children, $frontierBlockers] = $this->bridgeFrontier($chunk, $partition, (array) ($runtime['seen_partition_roots'] ?? []));
                if ($frontierBlockers !== []) {
                    unset($runtime['calculation_lease']);
                    $locked->forceFill([
                        'status' => 'REQUIRES_REVIEW', 'failed_partitions' => (int) $locked->failed_partitions + 1,
                        'runtime_checkpoint' => [...$runtime, 'blockers' => $frontierBlockers], 'heartbeat_at' => now(),
                    ])->save();

                    return $locked;
                }
                $pending = [...(array) ($runtime['pending_partitions'] ?? []), ...$children];
                $seen = array_values(array_unique([...(array) ($runtime['seen_partition_roots'] ?? []), ...array_column($children, 'allocation_id')]));
                $sourceRevisions = $this->expectedRootRevisions($locked, $runtime);
                foreach ($children as $child) {
                    $sourceRevisions[(int) $child['allocation_id']] = (int) $child['source_revision'];
                }
                $expected = (int) $locked->expected_partitions + count($children);
                $completed = (int) $locked->completed_partitions;

                if ($chunk['next_checkpoint'] !== null) {
                    $partition['checkpoint'] = $chunk['next_checkpoint'];
                    $current = $partition;
                } else {
                    $completed++;
                    CostRevaluationDelta::query()->where('run_id', $locked->id)->where('allocation_id', (int) $partition['allocation_id'])
                        ->update(['stock_projection_delta_value' => (string) $chunk['summary']['ending_delta_value']]);
                    $current = array_shift($pending);
                }
                $hasMore = $current !== null;
                $ready = ! $hasMore && $completed === $expected && (int) $locked->failed_partitions === 0;
                $rootDelta = CostRevaluationDelta::query()->where('run_id', $locked->id)->where('allocation_id', $locked->root_allocation_id)->value('delta_value') ?? 0;
                $runtime = [
                    'stage' => 'CALCULATION', 'source_revision' => (int) ($runtime['source_revision'] ?? 0),
                    'source_revisions' => $sourceRevisions,
                    'current_partition' => $current, 'pending_partitions' => $pending,
                    'seen_partition_roots' => $seen, 'blockers' => [], 'complete' => $ready,
                ];
                $locked->forceFill([
                    'status' => $ready ? 'PENDING_APPROVAL' : 'WAITING_CONTINUATION',
                    'posting_date' => $locked->posting_date ?: $chunk['summary']['posting_date'],
                    'nodes_affected' => CostRevaluationDelta::query()->where('run_id', $locked->id)->count(),
                    'nodes_scanned' => (int) $locked->nodes_scanned + (int) $chunk['summary']['nodes_scanned'],
                    'expected_partitions' => $expected, 'completed_partitions' => $completed,
                    'estimated_delta_value' => $rootDelta,
                    'shadow_snapshot' => ['calculation_contract_version' => 'cost-shadow-v2-async', 'summary' => ['impact_date' => data_get($locked->shadow_snapshot, 'summary.impact_date', $chunk['summary']['impact_date']), 'posting_date' => $locked->posting_date?->format('Y-m-d') ?: $chunk['summary']['posting_date']], 'variance_report' => ['status' => $ready ? 'READY_FOR_REVIEW' : 'CALCULATING', 'blockers' => []]],
                    'runtime_checkpoint' => $runtime,
                    'heartbeat_at' => now(),
                ])->save();

                return $locked->fresh();
            }, 3);
        } catch (Throwable $exception) {
            DB::transaction(function () use ($run, $leaseToken, $exception): void {
                $locked = CostRevaluationRun::query()->lockForUpdate()->find($run->id);
                if ($locked && data_get($locked->runtime_checkpoint, 'calculation_lease.token') === $leaseToken) {
                    $runtime = (array) $locked->runtime_checkpoint;
                    unset($runtime['calculation_lease']);
                    $locked->forceFill(['status' => 'FAILED_RETRYABLE', 'runtime_checkpoint' => $runtime, 'last_error' => mb_substr($exception->getMessage(), 0, 4000), 'heartbeat_at' => now()])->save();
                }
            }, 3);
            throw $exception;
        }
    }

    private function rootRevisionsAreCurrent(CostRevaluationRun $run, array $runtime): bool
    {
        $expected = $this->expectedRootRevisions($run, $runtime);
        $actual = CostAllocation::query()->whereIn('id', array_keys($expected))->pluck('revision', 'id');

        return count($expected) === $actual->count()
            && collect($expected)->every(fn ($revision, $id): bool => (int) $actual->get((int) $id, -1) === (int) $revision);
    }

    /** @return array<int,int> */
    private function expectedRootRevisions(CostRevaluationRun $run, array $runtime): array
    {
        $expected = (array) ($runtime['source_revisions'] ?? []);

        return $expected !== []
            ? array_map('intval', $expected)
            : [(int) $run->root_allocation_id => (int) ($runtime['source_revision'] ?? 0)];
    }

    private function stopStaleCalculation(CostRevaluationRun $run, string $leaseToken): CostRevaluationRun
    {
        return DB::transaction(function () use ($run, $leaseToken): CostRevaluationRun {
            $locked = CostRevaluationRun::query()->lockForUpdate()->findOrFail($run->id);
            $runtime = (array) $locked->runtime_checkpoint;

            return data_get($runtime, 'calculation_lease.token') === $leaseToken
                ? $this->markStale($locked, $runtime)
                : $locked;
        }, 3);
    }

    private function markStale(CostRevaluationRun $run, array $runtime): CostRevaluationRun
    {
        unset($runtime['calculation_lease']);
        $runtime['blockers'] = array_values(array_unique([...(array) ($runtime['blockers'] ?? []), 'STALE_ROOT_REVISION']));
        $run->forceFill([
            'status' => 'REQUIRES_REVIEW', 'failed_partitions' => (int) $run->failed_partitions + 1,
            'runtime_checkpoint' => $runtime, 'heartbeat_at' => now(),
        ])->save();

        return $run;
    }

    /** @param list<array<string,mixed>> $rows */
    private function persistChunkRows(CostRevaluationRun $run, array $rows): void
    {
        $warehouseIds = collect($rows)->pluck('warehouse_id')->filter()->unique()->values();
        $branches = $warehouseIds->isEmpty() ? collect() : DB::table('warehouses')->whereIn('id', $warehouseIds->all())->pluck('branch_id', 'id');
        foreach ($rows as $row) {
            $value = BigDecimal::of((string) ($row['estimated_delta_value'] ?? '0'));
            if ($value->isZero()) {
                continue;
            }
            $bucket = CostImpactBucket::from((string) $row['impact_bucket']);
            $warehouseId = (int) $row['warehouse_id'];
            $branchId = (int) $branches->get($warehouseId);
            if ($warehouseId < 1 || $branchId < 1 || ! empty($row['issues'])) {
                throw ValidationException::withMessages(['revaluation' => 'Chunk row ไม่มี Warehouse/Branch scope หรือมี blocker']);
            }
            CostRevaluationDelta::query()->firstOrCreate(['idempotency_key' => $run->idempotency_key.':allocation:'.$row['allocation_id']], [
                'run_id' => $run->id, 'allocation_id' => $row['allocation_id'], 'status' => 'PLANNED',
                'old_unit_cost' => $row['old_unit_cost'], 'new_unit_cost' => $row['estimated_new_unit_cost'],
                'delta_value' => $row['estimated_delta_value'], 'impact_bucket' => $bucket->value,
                'target_event' => $row['target_event'] ?? $bucket->targetEvent(), 'target_warehouse_id' => $warehouseId,
                'target_branch_id' => $branchId, 'stock_projection_delta_value' => '0.00000000',
            ]);
        }
    }

    /** @return array{0:list<array<string,mixed>>,1:list<string>} */
    private function bridgeFrontier(array $chunk, array $partition, array $seen): array
    {
        $edges = collect([...(array) data_get($chunk, 'direct_bridges.edges', []), ...(array) data_get($chunk, 'production_bridges.edges', [])])
            ->filter(fn (array $edge): bool => in_array($edge['relation'] ?? null, ['TRANSFER_ACCEPT', 'PRODUCTION_OUTPUT'], true) && empty($edge['issues']) && ($edge['estimated_delta_value'] ?? '0.00000000') !== '0.00000000')
            ->groupBy('child_allocation_id');
        $children = CostAllocation::query()->whereIn('id', $edges->keys()->map(fn ($id): int => (int) $id)->all())
            ->get(['id', 'revision', 'quantity', 'value'])->keyBy('id');
        $pending = [];
        $blockers = [];
        foreach ($edges as $childId => $group) {
            $childId = (int) $childId;
            if (in_array($childId, $seen, true)) {
                continue;
            }
            $depth = (int) ($partition['depth'] ?? 0) + 1;
            $path = array_map('intval', (array) ($partition['path'] ?? []));
            $child = $children->get($childId);
            if (! $child || $depth > 64 || in_array($childId, $path, true)) {
                $blockers[] = $depth > 64 ? 'MAX_DEPTH_REACHED' : (in_array($childId, $path, true) ? 'CYCLE_DETECTED' : 'BRIDGE_ROOT_MISSING');

                continue;
            }
            $quantity = BigDecimal::of((string) $child->quantity);
            $delta = $group->reduce(fn (BigDecimal $sum, array $edge): BigDecimal => $sum->plus((string) $edge['estimated_delta_value']), BigDecimal::zero());
            $newValue = BigDecimal::of((string) $child->value)->plus($delta);
            if ($quantity->isLessThanOrEqualTo(0) || $newValue->isNegative()) {
                $blockers[] = 'BRIDGE_COST_INVALID';

                continue;
            }
            $pending[] = ['allocation_id' => $childId, 'source_revision' => (int) $child->revision, 'proposed_unit_cost' => $newValue->dividedBy($quantity, 8, RoundingMode::HALF_UP)->__toString(), 'depth' => $depth, 'path' => [...$path, $childId], 'checkpoint' => null];
        }
        if (count($seen) + count($pending) > 1000) {
            return [[], ['PARTITION_LIMIT_REACHED']];
        }

        return [$pending, array_values(array_unique($blockers))];
    }

    public function queue(int $allocationId, string $proposedUnitCost, ?int $actorId = null): CostRevaluationRun
    {
        $shadow = app(CostShadowCalculationService::class)->calculate($allocationId, $proposedUnitCost);
        if (($shadow['variance_report']['status'] ?? null) !== 'READY_FOR_REVIEW') {
            throw ValidationException::withMessages(['revaluation' => 'Shadow ยังมี blocker ไม่สามารถสร้าง Apply Run ได้']);
        }

        // Include the calculation contract so a corrected replay algorithm
        // cannot reuse a run/delta set produced by an older contract.
        $key = hash('sha256', implode('|', [
            $shadow['calculation_contract_version'] ?? 'unknown',
            $allocationId,
            $proposedUnitCost,
            $shadow['summary']['impact_date'] ?? '',
            $shadow['summary']['impact_end_date'] ?? '',
        ]));
        $classifiedRows = $this->classifiedRows($shadow);

        return DB::transaction(function () use ($key, $allocationId, $proposedUnitCost, $actorId, $shadow, $classifiedRows): CostRevaluationRun {
            $run = CostRevaluationRun::query()->firstOrCreate(['idempotency_key' => $key], [
                'root_allocation_id' => $allocationId,
                'status' => 'PENDING_APPROVAL',
                'proposed_unit_cost' => $proposedUnitCost,
                'posting_date' => $shadow['summary']['posting_date'],
                'nodes_affected' => count($classifiedRows),
                'estimated_delta_value' => $shadow['summary']['estimated_delta_value'],
                'shadow_snapshot' => $shadow,
                'requested_by' => $actorId,
            ]);

            foreach ($classifiedRows as $row) {
                CostRevaluationDelta::query()->firstOrCreate(['idempotency_key' => $key.':allocation:'.$row['allocation_id']], [
                    'run_id' => $run->id,
                    'allocation_id' => $row['allocation_id'],
                    'status' => 'PLANNED',
                    'old_unit_cost' => $row['old_unit_cost'],
                    'new_unit_cost' => $row['estimated_new_unit_cost'],
                    'delta_value' => $row['estimated_delta_value'],
                    'impact_bucket' => $row['impact_bucket'],
                    'target_event' => $row['target_event'],
                    'target_warehouse_id' => $row['target_warehouse_id'],
                    'target_branch_id' => $row['target_branch_id'],
                    'stock_projection_delta_value' => $row['stock_projection_delta_value'],
                ]);
            }

            return $run->load('deltas');
        }, 3);
    }

    public function apply(CostRevaluationRun $run): CostRevaluationRun
    {
        if (! config('erp.inventory.revaluation_apply_enabled', false)) {
            throw ValidationException::withMessages(['revaluation' => 'Cost Revaluation Apply feature gate ยังปิดอยู่']);
        }

        do {
            $result = $this->applyChunk($run);
            $run = $result['run'];
        } while ($result['has_more'] && ! $result['busy']);

        return $run;
    }

    /** @return array{run:CostRevaluationRun,has_more:bool,processed:int,busy:bool} */
    public function applyChunk(CostRevaluationRun $run, ?int $limit = null, ?int $seconds = null): array
    {
        if (! config('erp.inventory.revaluation_apply_enabled', false)) {
            throw ValidationException::withMessages(['revaluation' => 'Cost Revaluation Apply feature gate ยังปิดอยู่']);
        }

        $limit = max(1, min($limit ?? (int) config('erp.inventory.revaluation_chunk_size', 250), 1000));
        $seconds = max(1, min($seconds ?? (int) config('erp.inventory.revaluation_chunk_seconds', 20), 50));
        $startedAt = microtime(true);

        [$run, $leaseToken] = DB::transaction(function () use ($run): array {
            $locked = CostRevaluationRun::query()->lockForUpdate()->findOrFail($run->id);
            $checkpoint = is_array($locked->runtime_checkpoint) ? $locked->runtime_checkpoint : [];
            $leaseSeconds = max(90, min((int) config('erp.inventory.revaluation_calculation_lease_seconds', 120), 600));
            $leaseActive = $locked->status === 'APPLYING'
                && $locked->heartbeat_at?->gt(now()->subSeconds($leaseSeconds));
            if ($leaseActive) {
                return [$locked, null];
            }
            if (! in_array($locked->status, ['APPROVED', 'APPLYING', 'WAITING_CONTINUATION', 'FAILED_RETRYABLE'], true)) {
                throw ValidationException::withMessages(['status' => 'Revaluation Run ต้องอยู่ในสถานะ APPROVED ก่อน Apply']);
            }
            if (in_array($locked->status, ['WAITING_CONTINUATION', 'FAILED_RETRYABLE'], true) && ($checkpoint['stage'] ?? null) !== 'APPLY') {
                throw ValidationException::withMessages(['status' => 'Run นี้ไม่ได้อยู่ใน Apply stage']);
            }
            $leaseToken = (string) Str::uuid();
            $checkpoint = [
                'stage' => 'APPLY',
                'last_delta_id' => ($checkpoint['stage'] ?? null) === 'APPLY' ? (int) ($checkpoint['last_delta_id'] ?? 0) : 0,
                'apply_lease' => ['token' => $leaseToken, 'claimed_at' => now()->toIso8601String()],
            ];
            $locked->forceFill(['status' => 'APPLYING', 'runtime_checkpoint' => $checkpoint, 'heartbeat_at' => now(), 'last_error' => null])->save();

            return [$locked, $leaseToken];
        }, 3);
        if ($leaseToken === null) {
            return ['run' => $run, 'has_more' => true, 'processed' => 0, 'busy' => true];
        }

        try {
            $afterId = (int) data_get($run->runtime_checkpoint, 'last_delta_id', 0);
            $deltaIds = CostRevaluationDelta::query()->where('run_id', $run->id)->where('id', '>', $afterId)
                ->orderBy('id')->limit($limit + 1)->pluck('id');
            $hasMore = $deltaIds->count() > $limit;
            $chunkIds = $deltaIds->take($limit)->map(fn ($id): int => (int) $id)->all();
            $preflight = app(CostRevaluationPreflightService::class)->check($run, $chunkIds);
            if (! $preflight['ready']) {
                throw ValidationException::withMessages(['preflight' => 'Preflight ไม่ผ่าน: '.implode(', ', $preflight['blockers'])]);
            }
            $processed = 0;
            $lastDeltaId = $afterId;

            foreach ($chunkIds as $deltaId) {
                if ($processed > 0 && microtime(true) - $startedAt >= $seconds) {
                    $hasMore = true;

                    break;
                }
                $owned = DB::transaction(function () use ($run, $leaseToken, $deltaId): bool {
                    $locked = CostRevaluationRun::query()->lockForUpdate()->findOrFail($run->id);
                    $runtime = (array) $locked->runtime_checkpoint;
                    if (data_get($runtime, 'apply_lease.token') !== $leaseToken) {
                        return false;
                    }
                    $delta = CostRevaluationDelta::query()->where('run_id', $locked->id)->lockForUpdate()->findOrFail($deltaId);
                    if ($delta->status !== 'APPLIED') {
                        $this->applyDelta($locked, $delta);
                    }
                    $runtime['last_delta_id'] = $deltaId;
                    $locked->forceFill(['runtime_checkpoint' => $runtime, 'nodes_scanned' => (int) $locked->nodes_scanned + 1, 'heartbeat_at' => now()])->save();

                    return true;
                }, 3);
                if (! $owned) {
                    return ['run' => $run->fresh(), 'has_more' => true, 'processed' => $processed, 'busy' => true];
                }
                $lastDeltaId = $deltaId;
                $processed++;
            }

            $hasMore = $hasMore || CostRevaluationDelta::query()->where('run_id', $run->id)->where('id', '>', $lastDeltaId)->exists();
            $run = DB::transaction(function () use ($run, $leaseToken, $lastDeltaId, $hasMore): CostRevaluationRun {
                $locked = CostRevaluationRun::query()->lockForUpdate()->findOrFail($run->id);
                $runtime = (array) $locked->runtime_checkpoint;
                if (data_get($runtime, 'apply_lease.token') !== $leaseToken) {
                    return $locked;
                }
                unset($runtime['apply_lease']);
                $runtime['last_delta_id'] = $lastDeltaId;
                $locked->forceFill([
                    'status' => $hasMore ? 'WAITING_CONTINUATION' : 'STOCK_PROJECTED',
                    'runtime_checkpoint' => $runtime, 'heartbeat_at' => now(),
                    'applied_at' => $hasMore ? null : now(),
                ])->save();

                return $locked;
            }, 3);

            return ['run' => $run->fresh(), 'has_more' => $hasMore, 'processed' => $processed, 'busy' => false];
        } catch (Throwable $exception) {
            DB::transaction(function () use ($run, $leaseToken, $exception): void {
                $locked = CostRevaluationRun::query()->lockForUpdate()->find($run->id);
                if ($locked && data_get($locked->runtime_checkpoint, 'apply_lease.token') === $leaseToken) {
                    $runtime = (array) $locked->runtime_checkpoint;
                    unset($runtime['apply_lease']);
                    $locked->forceFill(['status' => 'FAILED_RETRYABLE', 'runtime_checkpoint' => $runtime, 'last_error' => mb_substr($exception->getMessage(), 0, 4000), 'heartbeat_at' => now()])->save();
                }
            }, 3);
            throw $exception;
        }
    }

    private function applyDelta(CostRevaluationRun $run, CostRevaluationDelta $delta): void
    {
        $source = CostAllocation::query()->lockForUpdate()->findOrFail($delta->allocation_id);
        $value = BigDecimal::of((string) $delta->delta_value);
        $projectionDelta = BigDecimal::of((string) $delta->stock_projection_delta_value);
        if ($value->isZero() && $projectionDelta->isZero()) {
            $delta->forceFill(['status' => 'APPLIED', 'applied_at' => now()])->save();

            return;
        }
        $quantity = BigDecimal::of((string) $source->quantity);
        if ($quantity->isZero()) {
            throw ValidationException::withMessages(['allocation' => 'ไม่สามารถสร้าง RECOST delta จาก Allocation ที่มีจำนวนเป็นศูนย์']);
        }
        $economicDelta = $this->inventoryDelta($source, $value);
        $key = 'revaluation:run:'.$run->id.':allocation:'.$source->id;
        $applied = CostAllocation::query()->firstOrCreate(['idempotency_key' => $key], [
            'stock_movement_id' => $source->stock_movement_id,
            'stock_cost_layer_id' => $source->stock_cost_layer_id,
            'parent_allocation_id' => $source->id,
            'warehouse_id' => $source->warehouse_id,
            'item_id' => $source->item_id,
            'uom_id' => $source->uom_id,
            'allocation_type' => 'RECOST',
            'direction' => $economicDelta->isNegative() ? 'OUT' : 'IN',
            'cost_status' => 'FINAL',
            'status' => $delta->target_event === null ? 'POSTED' : 'PENDING',
            'method' => $source->method,
            'policy_version' => 'costing-v1',
            'revision' => $source->revision + 1,
            'quantity' => $source->quantity,
            'unit_cost' => $economicDelta->abs()->dividedBy($quantity, 8, RoundingMode::HALF_UP)->toScale(8, RoundingMode::HALF_UP)->__toString(),
            'value' => $economicDelta->abs()->__toString(),
            'business_date' => $source->business_date,
            'metadata' => ['revaluation_run_id' => $run->id, 'source_allocation_id' => $source->id, 'impact_bucket' => $delta->impact_bucket, 'target_event' => $delta->target_event],
        ]);
        if (! $projectionDelta->isZero()) {
            $this->adjustProjection($delta, $source, $projectionDelta);
        }
        $delta->forceFill(['status' => 'APPLIED', 'applied_cost_allocation_id' => $applied->id, 'applied_at' => now()])->save();
    }

    private function adjustProjection(CostRevaluationDelta $delta, CostAllocation $source, BigDecimal $value): void
    {
        $key = ['warehouse_id' => $delta->target_warehouse_id, 'item_id' => $source->item_id, 'uom_id' => $source->uom_id];
        $balance = StockBalance::query()->where($key)->lockForUpdate()->first();
        if (! $balance) {
            throw ValidationException::withMessages(['stock_projection' => 'ไม่พบ Stock Projection สำหรับ RECOST delta']);
        }
        $inventoryValue = BigDecimal::of((string) $balance->inventory_value)->plus($value);
        $onHand = BigDecimal::of((string) $balance->on_hand);
        if ($onHand->isZero()) {
            $inventoryValue = BigDecimal::zero();
        }
        $average = $onHand->isPositive() ? $inventoryValue->dividedBy($onHand, 8, RoundingMode::HALF_UP) : BigDecimal::zero();
        $balance->update(['inventory_value' => $inventoryValue->toScale(2, RoundingMode::HALF_UP)->__toString(), 'average_unit_cost' => $average->toScale(8, RoundingMode::HALF_UP)->__toString()]);
    }

    private function inventoryDelta(CostAllocation $allocation, BigDecimal $value): BigDecimal
    {
        return $allocation->direction === 'OUT' ? $value->negated() : $value;
    }

    /** @return list<array<string, mixed>> */
    private function classifiedRows(array $shadow): array
    {
        $partitions = [[
            'root' => $shadow['root'] ?? [],
            'impact_summary' => $shadow['impact_summary'] ?? [],
            'rows' => $shadow['rows'] ?? [],
        ], ...($shadow['bridge_replays']['partitions'] ?? [])];
        $rows = [];

        foreach ($partitions as $partition) {
            $rootId = (int) ($partition['root']['allocation_id'] ?? 0);
            $projection = (string) ($partition['impact_summary']['ending_on_hand_delta_value'] ?? '0');
            foreach ($partition['rows'] ?? [] as $row) {
                $allocationId = (int) ($row['allocation_id'] ?? 0);
                $bucket = CostImpactBucket::tryFrom((string) ($row['impact_bucket'] ?? ''));
                if ($allocationId < 1 || ! $bucket || ! empty($row['issues'])) {
                    throw ValidationException::withMessages(['revaluation' => "Allocation {$allocationId} ไม่มี classified impact ที่พร้อม Apply"]);
                }
                $projectionDelta = $allocationId === $rootId ? $projection : '0.00000000';
                if (BigDecimal::of((string) ($row['estimated_delta_value'] ?? '0'))->isZero() && BigDecimal::of($projectionDelta)->isZero()) {
                    continue;
                }
                if (isset($rows[$allocationId])) {
                    throw ValidationException::withMessages(['revaluation' => "Allocation {$allocationId} ปรากฏซ้ำใน recursive impact"]);
                }
                $rows[$allocationId] = [
                    ...$row,
                    'target_event' => $row['target_event'] ?? $bucket->targetEvent(),
                    'target_warehouse_id' => (int) ($row['warehouse_id'] ?? 0),
                    'stock_projection_delta_value' => $projectionDelta,
                ];
            }
        }

        $warehouseIds = collect($rows)->pluck('target_warehouse_id')->filter()->unique()->values();
        $branches = $warehouseIds->isEmpty() ? collect() : DB::table('warehouses')->whereIn('id', $warehouseIds->all())->pluck('branch_id', 'id');
        foreach ($rows as $allocationId => &$row) {
            $row['target_branch_id'] = ($branch = $branches->get($row['target_warehouse_id'])) ? (int) $branch : null;
            if ($row['target_warehouse_id'] < 1 || $row['target_branch_id'] === null) {
                throw ValidationException::withMessages(['revaluation' => "Allocation {$allocationId} ไม่มี Warehouse/Branch scope ที่พร้อม Apply"]);
            }
        }

        return array_values($rows);
    }
}
