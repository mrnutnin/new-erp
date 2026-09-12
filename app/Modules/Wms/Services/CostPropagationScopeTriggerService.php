<?php

namespace App\Modules\Wms\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Wms\Jobs\DispatchCostRevaluationScope;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostRevaluationBatch;
use App\Modules\Wms\Models\Item;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CostPropagationScopeTriggerService
{
    private const PARTITION_COLUMNS = ['warehouse_id', 'item_id', 'uom_id', 'method'];

    public function __construct(private readonly CostRevaluationApplyService $revaluations) {}

    /** @return list<array{id:int,code:string,name:string,warehouses:list<array{id:int,code:string,name:string}>}> */
    public function branches(User $actor): array
    {
        $warehouseIds = $actor->warehouses()->where('warehouses.is_active', true)->pluck('warehouses.id');

        return Warehouse::query()->with('branch:id,code,name')->whereIn('id', $warehouseIds)
            ->orderBy('branch_id')->orderBy('name')->get(['id', 'branch_id', 'code', 'name'])
            ->groupBy('branch_id')->map(function ($warehouses): array {
                $branch = $warehouses->first()->branch;

                return [
                    'id' => (int) $branch->id,
                    'code' => (string) $branch->code,
                    'name' => (string) $branch->name,
                    'warehouses' => $warehouses->map(fn (Warehouse $warehouse): array => [
                        'id' => (int) $warehouse->id,
                        'code' => (string) $warehouse->code,
                        'name' => (string) $warehouse->name,
                    ])->values()->all(),
                ];
            })->values()->all();
    }

    /** @return list<array{id:int,text:string}> */
    public function itemOptions(User $actor, int $branchId, array $warehouseIds, ?string $search = null): array
    {
        $scope = $this->scope($actor, $branchId, 'SELECTED', $warehouseIds, 'ALL', [], now()->toDateString());
        $search = trim((string) $search);

        return Item::query()->where('is_active', true)->where('is_stock_item', true)
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('wms_cost_allocations as scope_allocations')
                ->whereColumn('scope_allocations.item_id', 'wms_items.id')->whereIn('scope_allocations.warehouse_id', $scope['warehouse_ids']))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $prefix = addcslashes($search, '\\%_').'%';
                $query->where(fn (Builder $query) => $query->where('code', 'like', $prefix)->orWhere('name', 'like', $prefix));
            })->orderBy('code')->limit(20)->get(['id', 'code', 'name'])
            ->map(fn (Item $item): array => ['id' => (int) $item->id, 'text' => trim($item->code.' · '.$item->name)])->all();
    }

    /** @return array<string,mixed> */
    public function preview(User $actor, int $branchId, string $warehouseMode, array $warehouseIds, string $itemMode, array $itemIds, string $startDate): array
    {
        $scope = $this->scope($actor, $branchId, $warehouseMode, $warehouseIds, $itemMode, $itemIds, $startDate);
        $query = $this->allocationQuery($scope);
        $proofState = (clone $this->baseAllocationQuery($scope))
            ->selectRaw('MAX(updated_at) AS latest_allocation_update, SUM(CASE WHEN journal_entry_id IS NOT NULL THEN 1 ELSE 0 END) AS journal_linked_count, COUNT(*) AS allocation_count')
            ->first();
        $scope['proof_fingerprint'] = implode('|', [
            'legacy-proof-v4-journal-aware-timeline',
            (string) ($proofState?->latest_allocation_update ?? ''),
            (int) ($proofState?->journal_linked_count ?? 0),
            (int) ($proofState?->allocation_count ?? 0),
        ]);
        $availability = $this->baseAllocationQuery($scope)
            ->selectRaw('MIN(business_date) AS first_date, MAX(business_date) AS last_date, COUNT(*) AS allocations')->first();
        $partitions = (clone $query)->select(self::PARTITION_COLUMNS)->distinct();
        $partitionCount = DB::query()->fromSub($partitions, 'scope_partitions')->count();
        $horizon = (int) ((clone $query)->max('id') ?? 0);
        $sample = (clone $query)->select(self::PARTITION_COLUMNS)->groupBy(self::PARTITION_COLUMNS)
            ->orderBy('warehouse_id')->orderBy('item_id')->orderBy('uom_id')->orderBy('method')->limit(20)->get()
            ->map(fn (CostAllocation $row): array => [
                'partition_key' => $this->partitionKey($row),
                'warehouse_id' => (int) $row->warehouse_id,
                'item_id' => (int) $row->item_id,
                'uom_id' => (int) $row->uom_id,
                'method' => (string) $row->method,
            ])->all();

        return [
            'scope' => [...$scope, 'horizon_allocation_id' => $horizon],
            'summary' => [
                'ready' => $partitionCount > 0,
                'warehouses' => count($scope['warehouse_ids']),
                'items' => $scope['item_mode'] === 'ALL' ? 'ALL' : count($scope['item_ids']),
                'partitions' => $partitionCount,
                'planning_jobs' => (int) ceil($partitionCount / $this->chunkSize()),
                'calculation_jobs' => $partitionCount,
            ],
            'sample_partitions' => $sample,
            'availability' => [
                'first_date' => $availability?->first_date,
                'last_date' => $availability?->last_date,
                'allocations' => (int) ($availability?->allocations ?? 0),
            ],
            'blockers' => $partitionCount > 0 ? [] : ['SCOPE_HAS_NO_COST_ALLOCATIONS'],
            'messages' => $partitionCount > 0 ? [] : [
                $availability?->last_date
                    ? "ไม่พบรายการตั้งแต่ {$scope['start_date']} ข้อมูลล่าสุดของขอบเขตนี้อยู่วันที่ {$availability->last_date} กรุณาเลือกวันที่ไม่เกินวันที่ดังกล่าว"
                    : 'ยังไม่มี Cost Allocation ในสาขา คลัง และสินค้าที่เลือก',
            ],
        ];
    }

    public function trigger(User $actor, int $branchId, string $warehouseMode, array $warehouseIds, string $itemMode, array $itemIds, string $startDate, string $reason, ?string $ipAddress = null, ?string $userAgent = null): CostRevaluationBatch
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['scope_reason' => 'กรุณาระบุเหตุผลอย่างน้อย 10 ตัวอักษร']);
        }
        $preview = $this->preview($actor, $branchId, $warehouseMode, $warehouseIds, $itemMode, $itemIds, $startDate);
        if (! $preview['summary']['ready']) {
            throw ValidationException::withMessages(['scope' => 'ไม่พบ Cost Allocation ในขอบเขตที่เลือก']);
        }
        $scope = $preview['scope'];
        $identity = hash('sha256', json_encode(['manual-scope-v1', $scope], JSON_THROW_ON_ERROR));

        $batch = DB::transaction(function () use ($actor, $reason, $ipAddress, $userAgent, $preview, $scope, $identity): CostRevaluationBatch {
            $snapshot = [
                'scope' => [...$scope, 'cursor' => null, 'planning_complete' => false, 'dispatched_partitions' => 0],
                'summary' => $preview['summary'],
                'sample_partitions' => $preview['sample_partitions'],
            ];
            $batch = CostRevaluationBatch::query()->firstOrCreate(['idempotency_key' => $identity], [
                'source_document_type' => 'MANUAL_SCOPE',
                'source_document_id' => $scope['horizon_allocation_id'],
                'source_document_reference' => 'SCOPE '.$scope['start_date'],
                'document_date' => $scope['start_date'],
                'source_revision' => 0,
                'status' => 'PLANNING',
                'expected_root_lines' => $preview['summary']['partitions'],
                'resolved_root_lines' => 0,
                'expected_partitions' => $preview['summary']['partitions'],
                'completed_partitions' => 0,
                'failed_partitions' => 0,
                'blockers' => [],
                'trigger_snapshot' => $snapshot,
                'requested_by' => $actor->id,
                'heartbeat_at' => now(),
            ]);
            AuditLog::query()->create([
                'user_id' => $actor->id,
                'action' => 'wms.cost_revaluation.manual_scope_triggered',
                'subject_type' => $batch->getMorphClass(),
                'subject_id' => $batch->id,
                'old_values' => null,
                'new_values' => ['reason' => trim($reason), 'scope' => $scope, 'expected_partitions' => $preview['summary']['partitions']],
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            return $batch;
        }, 3);

        if (! data_get($batch->trigger_snapshot, 'scope.planning_complete', false)) {
            DispatchCostRevaluationScope::dispatch($batch->id)->afterCommit();
        }

        return $batch->fresh('runs');
    }

    /** Emergency rebuild is deliberately bounded to one branch and all its accessible warehouses. */
    public function emergencyPreview(User $actor, int $branchId): array
    {
        $branch = Branch::query()->findOrFail($branchId);
        $warehouseIds = $actor->warehouses()->where('warehouses.is_active', true)->where('warehouses.branch_id', $branchId)->orderBy('warehouses.id')->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
        if ($warehouseIds === []) {
            throw ValidationException::withMessages(['branch_id' => 'ไม่มีสิทธิ์เข้าถึงคลังในสาขาที่เลือก']);
        }
        $startDate = CostAllocation::query()->whereIn('warehouse_id', $warehouseIds)->where('status', '!=', 'REVERSED')->min('business_date');
        $preview = $startDate === null ? null : $this->preview($actor, $branchId, 'ALL', $warehouseIds, 'ALL', [], (string) $startDate);

        return [
            'branch' => ['id' => (int) $branch->id, 'code' => (string) $branch->code, 'name' => (string) $branch->name],
            'warehouse_ids' => $warehouseIds,
            'start_date' => $startDate,
            'typed_confirmation' => 'EMERGENCY REBUILD '.strtoupper((string) $branch->code),
            'preview' => $preview,
        ];
    }

    public function emergencyTrigger(User $actor, int $branchId, string $typedConfirmation, string $reason, ?string $ipAddress = null, ?string $userAgent = null): CostRevaluationBatch
    {
        $emergency = $this->emergencyPreview($actor, $branchId);
        if (trim($typedConfirmation) !== $emergency['typed_confirmation']) {
            throw ValidationException::withMessages(['typed_confirmation' => 'ข้อความยืนยันไม่ถูกต้อง ต้องพิมพ์ '.$emergency['typed_confirmation']]);
        }
        if (mb_strlen(trim($reason)) < 20) {
            throw ValidationException::withMessages(['reason' => 'เหตุผล Emergency rebuild ต้องมีอย่างน้อย 20 ตัวอักษร']);
        }
        if (! $emergency['start_date']) {
            throw ValidationException::withMessages(['scope' => 'ไม่พบ Cost Allocation สำหรับ rebuild ในสาขานี้']);
        }

        $batch = $this->trigger($actor, $branchId, 'ALL', $emergency['warehouse_ids'], 'ALL', [], (string) $emergency['start_date'], '[EMERGENCY REBUILD] '.$reason, $ipAddress, $userAgent);
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => 'wms.cost_revaluation.emergency_rebuild_requested',
            'subject_type' => $batch->getMorphClass(),
            'subject_id' => $batch->id,
            'old_values' => null,
            'new_values' => ['typed_confirmation' => $typedConfirmation, 'reason' => trim($reason), 'branch_id' => $branchId, 'warehouse_ids' => $emergency['warehouse_ids'], 'start_date' => $emergency['start_date']],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        return $batch;
    }

    /** Return true when another bounded planning chunk is required. */
    public function dispatchChunk(int $batchId): bool
    {
        $batch = CostRevaluationBatch::query()->findOrFail($batchId);
        $snapshot = (array) $batch->trigger_snapshot;
        $scope = (array) ($snapshot['scope'] ?? []);
        if ($batch->source_document_type !== 'MANUAL_SCOPE' || ($scope['planning_complete'] ?? false)) {
            return false;
        }

        $query = $this->allocationQuery($scope);
        $this->afterCursor($query, is_array($scope['cursor'] ?? null) ? $scope['cursor'] : null);
        $groups = $query->select(self::PARTITION_COLUMNS)->groupBy(self::PARTITION_COLUMNS)
            ->orderBy('warehouse_id')->orderBy('item_id')->orderBy('uom_id')->orderBy('method')
            ->limit($this->chunkSize() + 1)->get();
        $hasMore = $groups->count() > $this->chunkSize();
        $groups = $groups->take($this->chunkSize());
        $dispatched = 0;
        $blockers = [];

        foreach ($groups as $group) {
            $root = $this->allocationQuery($scope)->where('warehouse_id', $group->warehouse_id)
                ->where('item_id', $group->item_id)->where('uom_id', $group->uom_id)->where('method', $group->method)
                ->orderBy('business_date')->orderBy('stock_movement_id')->orderBy('id')->first(['id', 'revision', 'unit_cost']);
            if (! $root) {
                $blockers[] = 'PARTITION_ROOT_MISSING:'.$this->partitionKey($group);

                continue;
            }
            $partitionKey = $this->partitionKey($group);
            $this->revaluations->dispatchPartitionCalculation(
                [(int) $root->id => (string) $root->unit_cost],
                $batch->requested_by,
                $batch->id,
                $partitionKey,
                hash('sha256', $batch->idempotency_key.'|'.$partitionKey.'|'.$root->id.'|'.$root->revision),
            );
            $dispatched++;
        }

        $last = $groups->last();
        DB::transaction(function () use ($batchId, $snapshot, $scope, $last, $hasMore, $dispatched, $blockers): void {
            $locked = CostRevaluationBatch::query()->lockForUpdate()->findOrFail($batchId);
            $latest = (array) $locked->trigger_snapshot;
            $latestScope = (array) ($latest['scope'] ?? $scope);
            $latestScope['cursor'] = $last ? $this->cursor($last) : ($latestScope['cursor'] ?? null);
            $latestScope['planning_complete'] = ! $hasMore;
            $latestScope['dispatched_partitions'] = (int) ($latestScope['dispatched_partitions'] ?? 0) + $dispatched;
            $locked->forceFill([
                'status' => $hasMore ? 'PLANNING' : 'CALCULATING',
                'resolved_root_lines' => $latestScope['dispatched_partitions'],
                'failed_partitions' => (int) $locked->failed_partitions + count($blockers),
                'blockers' => array_values(array_unique([...(array) $locked->blockers, ...$blockers])),
                'trigger_snapshot' => [...$snapshot, ...$latest, 'scope' => $latestScope],
                'heartbeat_at' => now(),
            ])->save();
        }, 3);

        if (! $hasMore) {
            app(CostRevaluationBatchService::class)->sync($batchId);
        }

        return $hasMore;
    }

    /** @return array{branch_id:int,warehouse_ids:list<int>,item_mode:string,item_ids:list<int>,start_date:string,horizon_allocation_id?:int,cursor?:array,planning_complete?:bool,dispatched_partitions?:int} */
    private function scope(User $actor, int $branchId, string $warehouseMode, array $warehouseIds, string $itemMode, array $itemIds, string $startDate): array
    {
        try {
            $date = CarbonImmutable::parse($startDate)->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages(['start_date' => 'วันที่เริ่มคำนวณไม่ถูกต้อง']);
        }
        if ($date->isFuture()) {
            throw ValidationException::withMessages(['start_date' => 'วันที่เริ่มคำนวณต้องไม่เกินวันนี้']);
        }
        $accessible = $actor->warehouses()->where('warehouses.is_active', true)->where('warehouses.branch_id', $branchId)
            ->orderBy('warehouses.id')->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
        if ($accessible === []) {
            throw ValidationException::withMessages(['branch_id' => 'ไม่มีสิทธิ์เข้าถึงคลังในสาขาที่เลือก']);
        }
        $warehouseMode = strtoupper($warehouseMode);
        $warehouseIds = $warehouseMode === 'ALL' ? $accessible : $this->ids($warehouseIds);
        if ($warehouseIds === [] || array_diff($warehouseIds, $accessible) !== []) {
            throw ValidationException::withMessages(['warehouse_ids' => 'คลังที่เลือกอยู่นอกสิทธิ์หรือไม่อยู่ในสาขาที่เลือก']);
        }
        $itemMode = strtoupper($itemMode);
        if (! in_array($itemMode, ['ALL', 'SELECTED'], true)) {
            throw ValidationException::withMessages(['item_mode' => 'ขอบเขตสินค้าไม่ถูกต้อง']);
        }
        $itemIds = $itemMode === 'ALL' ? [] : $this->ids($itemIds);
        if ($itemMode === 'SELECTED' && ($itemIds === [] || Item::query()->whereIn('id', $itemIds)->where('is_stock_item', true)->count() !== count($itemIds))) {
            throw ValidationException::withMessages(['item_ids' => 'กรุณาเลือกสินค้าคงคลังที่ถูกต้องอย่างน้อยหนึ่งรายการ']);
        }

        return ['branch_id' => $branchId, 'warehouse_ids' => $warehouseIds, 'item_mode' => $itemMode, 'item_ids' => $itemIds, 'start_date' => $date->toDateString()];
    }

    private function allocationQuery(array $scope): Builder
    {
        return $this->baseAllocationQuery($scope)
            ->where('business_date', '>=', $scope['start_date'])
            ->when((int) ($scope['horizon_allocation_id'] ?? 0) > 0, fn (Builder $query) => $query->where('id', '<=', (int) $scope['horizon_allocation_id']));
    }

    private function baseAllocationQuery(array $scope): Builder
    {
        return CostAllocation::query()->whereIn('warehouse_id', $scope['warehouse_ids'])
            ->when(($scope['item_ids'] ?? []) !== [], fn (Builder $query) => $query->whereIn('item_id', $scope['item_ids']))
            ->where('status', '!=', 'REVERSED');
    }

    private function afterCursor(Builder $query, ?array $cursor): void
    {
        if (! $cursor) {
            return;
        }
        $query->where(function (Builder $query) use ($cursor): void {
            $query->where('warehouse_id', '>', $cursor['warehouse_id'])
                ->orWhere(fn (Builder $query) => $query->where('warehouse_id', $cursor['warehouse_id'])->where('item_id', '>', $cursor['item_id']))
                ->orWhere(fn (Builder $query) => $query->where('warehouse_id', $cursor['warehouse_id'])->where('item_id', $cursor['item_id'])->where('uom_id', '>', $cursor['uom_id']))
                ->orWhere(fn (Builder $query) => $query->where('warehouse_id', $cursor['warehouse_id'])->where('item_id', $cursor['item_id'])->where('uom_id', $cursor['uom_id'])->where('method', '>', $cursor['method']));
        });
    }

    private function cursor($row): array
    {
        return ['warehouse_id' => (int) $row->warehouse_id, 'item_id' => (int) $row->item_id, 'uom_id' => (int) $row->uom_id, 'method' => (string) $row->method];
    }

    private function partitionKey($row): string
    {
        return implode(':', [(int) $row->warehouse_id, (int) $row->item_id, (int) $row->uom_id, strtoupper((string) $row->method)]);
    }

    private function ids(array $ids): array
    {
        return collect($ids)->map(fn ($id): int => (int) $id)->filter(fn (int $id): bool => $id > 0)->unique()->sort()->values()->all();
    }

    private function chunkSize(): int
    {
        return max(1, min((int) config('erp.inventory.revaluation_scope_partition_chunk', 50), 100));
    }
}
