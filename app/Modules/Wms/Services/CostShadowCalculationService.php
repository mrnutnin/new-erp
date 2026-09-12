<?php

namespace App\Modules\Wms\Services;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockCostLayer;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Phase 2 read-only calculator. It estimates downstream impact from an
 * immutable allocation; it never writes allocation, balance, or journal data.
 */
final class CostShadowCalculationService
{
    public function __construct(
        private readonly CostTimelineReader $timelines,
        private readonly AvgPoolResolver $avgPool,
        private readonly FifoLayerResolver $fifoLayers,
        private readonly CostDirectBridgeResolver $directBridges,
        private readonly ProductionBridgeResolver $productionBridges,
    ) {}

    public function calculate(int $allocationId, string $proposedUnitCost, int $limit = 5000): array
    {
        $limit = max(1, min($limit, 10000));
        $this->assertUnitCost($proposedUnitCost);

        return $this->withBridgeReplays(
            $this->withProductionBridges($this->calculatePartition($allocationId, $proposedUnitCost, $limit)),
            $limit,
        );
    }

    /**
     * Calculates one keyset page for an async partition worker. The returned
     * checkpoint contains resolver state only; calculated rows stay outside it.
     *
     * @param  array<string,mixed>|null  $checkpoint
     * @return array<string,mixed>
     */
    public function calculatePartitionChunk(int $allocationId, string $proposedUnitCost, ?array $checkpoint = null, int $limit = 250, array $rootOverrides = []): array
    {
        $limit = max(1, min($limit, 1000));
        $this->assertUnitCost($proposedUnitCost);
        $rootOverrides = $rootOverrides ?: [['allocation_id' => $allocationId, 'proposed_unit_cost' => $proposedUnitCost]];
        $overrideCosts = collect($rootOverrides)->mapWithKeys(function (array $override): array {
            if ((int) ($override['allocation_id'] ?? 0) < 1) {
                throw ValidationException::withMessages(['allocation_id' => 'Root Allocation id ต้องมากกว่าศูนย์']);
            }
            $this->assertUnitCost((string) ($override['proposed_unit_cost'] ?? ''));

            return [(int) ($override['allocation_id'] ?? 0) => (string) $override['proposed_unit_cost']];
        })->filter(fn (string $cost, int $id): bool => $id > 0);
        $roots = CostAllocation::query()->with('movement')->whereIn('id', $overrideCosts->keys()->all())->get()->keyBy('id');
        if ($roots->count() !== $overrideCosts->count()) {
            throw ValidationException::withMessages(['allocation_id' => 'ไม่พบ Root Allocation บางรายการของ partition']);
        }
        $root = $roots->get($allocationId) ?? $roots->sortBy(fn (CostAllocation $candidate): string => ($candidate->business_date?->format('Y-m-d') ?? '').':'.str_pad((string) $candidate->id, 20, '0', STR_PAD_LEFT))->first();
        $method = strtoupper((string) $root->method);
        if (! in_array($method, ['AVG', 'FIFO'], true)) {
            throw ValidationException::withMessages(['allocation_id' => "Costing Method {$method} ยังไม่รองรับ async continuation"]);
        }
        $impactDate = $root->movement?->business_date?->format('Y-m-d') ?: $root->business_date?->format('Y-m-d');
        if (! $impactDate) {
            throw ValidationException::withMessages(['allocation_id' => 'ไม่พบ business date ของ Cost Allocation ต้นทาง']);
        }
        $partitionIdentity = [(int) $root->warehouse_id, (int) $root->item_id, (int) $root->uom_id, $method];
        foreach ($roots as $candidate) {
            if ([(int) $candidate->warehouse_id, (int) $candidate->item_id, (int) $candidate->uom_id, strtoupper((string) $candidate->method)] !== $partitionIdentity) {
                throw ValidationException::withMessages(['allocation_id' => 'Root Allocation อยู่คนละ cost partition']);
            }
            $candidateDate = $candidate->movement?->business_date?->format('Y-m-d') ?: $candidate->business_date?->format('Y-m-d');
            $impactDate = min($impactDate, (string) $candidateDate);
        }
        $sourceRevisions = $roots->mapWithKeys(fn (CostAllocation $candidate): array => [(string) $candidate->id => (int) $candidate->revision])->sortKeys()->all();
        if ($checkpoint !== null && (
            (int) ($checkpoint['root_allocation_id'] ?? 0) !== $allocationId
            || (array) ($checkpoint['source_revisions'] ?? [(string) $allocationId => (int) ($checkpoint['source_revision'] ?? -1)]) !== $sourceRevisions
            || ($checkpoint['method'] ?? null) !== $method
            || ($checkpoint['impact_date'] ?? null) !== $impactDate
        )) {
            throw ValidationException::withMessages(['checkpoint' => 'Checkpoint ไม่ตรงกับ Source Allocation revision หรือ cost partition ปัจจุบัน']);
        }

        $memoryStart = memory_get_usage(true);
        $page = $this->timelines->read(
            (int) $root->warehouse_id,
            (int) $root->item_id,
            (int) $root->uom_id,
            $method,
            $impactDate,
            $checkpoint['cursor'] ?? null,
            $limit,
        );
        $resolverAnchor = $checkpoint['resolver_state'] ?? $page['anchor'];
        $rootSeenIds = collect($checkpoint['root_seen_ids'] ?? ((bool) ($checkpoint['root_seen'] ?? false) ? [$allocationId] : []))->map(fn ($id): int => (int) $id);
        $pageAllocationIds = collect($page['rows'])->pluck('allocation_id')->map(fn ($id): int => (int) $id);
        $overrides = $overrideCosts->only($pageAllocationIds->all())->map(fn (string $cost): array => ['unit_cost' => $cost])->all();
        $resolved = $method === 'AVG'
            ? $this->avgPool->resolve($resolverAnchor, $page['rows'], $overrides)
            : $this->fifoLayers->resolve($resolverAnchor, $page['rows'], $overrides);
        $rootSeenIds = $rootSeenIds->merge(array_keys($overrides))->unique()->values();
        $timelineRows = collect($page['rows'])->keyBy('allocation_id');
        $rows = array_map(
            fn (array $row): array => $method === 'AVG'
                ? $this->avgShadowRow($row, $timelineRows->get((int) $row['allocation_id'], []))
                : $this->fifoShadowRow($row, $timelineRows->get((int) $row['allocation_id'], [])),
            $resolved['rows'],
        );
        $blockers = [...$resolved['blockers']];
        foreach ($page['rows'] as $row) {
            foreach ($row['warnings'] ?? [] as $warning) {
                $blockers[] = 'ALLOCATION_'.(int) $row['allocation_id'].':'.$warning;
            }
        }
        if ($rootSeenIds->count() !== $overrideCosts->count() && ! $page['page']['has_more']) {
            $blockers[] = 'ROOT_ALLOCATION_NOT_REACHED';
        }
        $memoryBudget = max(16, (int) config('erp.inventory.revaluation_memory_budget_mb', 128)) * 1024 * 1024;
        if (memory_get_usage(true) - $memoryStart > $memoryBudget) {
            $blockers[] = 'MEMORY_LIMIT_REACHED';
        }
        $blockers = array_values(array_unique($blockers));
        $hasMore = (bool) $page['page']['has_more'] && ! ($resolved['summary']['converged'] ?? false) && $blockers === [];
        $resolverState = $method === 'AVG'
            ? ['status' => 'READY', 'blockers' => [], 'old' => $resolved['summary']['ending_old'], 'new' => $resolved['summary']['ending_new'], 'propagation' => $resolved['summary']['propagation']]
            : ['status' => 'READY', 'blockers' => [], 'old' => ['layers' => $resolved['summary']['old_layers']], 'new' => ['layers' => $resolved['summary']['new_layers']], 'propagation' => $resolved['summary']['propagation']];
        $direct = $this->directBridges->resolve($rows);
        $production = $this->productionBridges->resolve($rows);
        $blockers = array_values(array_unique([...$blockers, ...$direct['blockers'], ...$production['blockers']]));
        $hasMore = $hasMore && $blockers === [];

        return [
            'read_only' => true,
            'ready' => $blockers === [],
            'complete' => ! $hasMore,
            'rows' => $rows,
            'direct_bridges' => $direct,
            'production_bridges' => $production,
            'blockers' => $blockers,
            'next_checkpoint' => $hasMore ? [
                'stage' => 'CALCULATION',
                'root_allocation_id' => $allocationId,
                'source_revision' => (int) $root->revision,
                'source_revisions' => $sourceRevisions,
                'method' => $method,
                'impact_date' => $impactDate,
                'cursor' => $page['page']['next_cursor'],
                'resolver_state' => $resolverState,
                'root_seen' => $rootSeenIds->contains($allocationId),
                'root_seen_ids' => $rootSeenIds->all(),
                'nodes_scanned' => (int) ($checkpoint['nodes_scanned'] ?? 0) + count($page['rows']),
            ] : null,
            'summary' => [
                'impact_date' => $impactDate,
                ...$this->periodPolicy($impactDate),
                'nodes_scanned' => count($page['rows']),
                'nodes_scanned_total' => (int) ($checkpoint['nodes_scanned'] ?? 0) + count($page['rows']),
                'nodes_affected' => (int) ($resolved['summary']['nodes_affected'] ?? 0),
                'ending_delta_value' => (string) ($resolved['summary']['ending_delta_value'] ?? '0.00000000'),
                'converged' => (bool) ($resolved['summary']['converged'] ?? false),
                'memory_growth_bytes' => max(0, memory_get_usage(true) - $memoryStart),
            ],
        ];
    }

    private function calculatePartition(int $allocationId, string $proposedUnitCost, int $limit): array
    {
        $root = CostAllocation::query()
            ->with('movement')
            ->findOrFail($allocationId);

        $movementDate = $root->movement?->business_date?->format('Y-m-d') ?: $root->business_date?->format('Y-m-d');
        if (! $movementDate) {
            throw ValidationException::withMessages(['allocation_id' => 'ไม่พบ business date ของ Cost Allocation ต้นทาง']);
        }

        if (strtoupper((string) $root->method) === 'AVG') {
            return $this->calculateAvg($root, $proposedUnitCost, $movementDate, $limit);
        }
        if (strtoupper((string) $root->method) === 'FIFO') {
            return $this->calculateFifo($root, $proposedUnitCost, $movementDate, $limit);
        }

        $anchor = $this->historicalAnchor($root, $movementDate);
        $allocations = CostAllocation::query()
            ->with('movement')
            ->where('business_date', '>=', $movementDate)
            ->where('status', '!=', 'REVERSED')
            ->orderBy('id')
            ->limit(max(1, min($limit, 10000)))
            ->get([
                'id', 'stock_movement_id', 'stock_cost_layer_id', 'parent_allocation_id',
                'warehouse_id', 'item_id', 'uom_id', 'allocation_type', 'direction',
                'cost_status', 'status', 'method', 'quantity', 'unit_cost', 'value',
                'business_date',
            ]);
        $byId = $allocations->keyBy('id');
        if (! $byId->has($root->id)) {
            $allocations->push($root);
            $byId->put($root->id, $root);
        }

        $children = $allocations->groupBy(fn (CostAllocation $allocation): int => (int) ($allocation->parent_allocation_id ?: 0));
        $method = strtoupper((string) $root->method);
        $currentUnit = BigDecimal::of((string) $root->unit_cost);
        $deltaUnit = BigDecimal::of($proposedUnitCost)->minus($currentUnit);
        $rows = [];
        $queue = [[
            'allocation' => $root,
            'delta_value' => BigDecimal::of((string) $root->quantity)->multipliedBy($deltaUnit),
            'depth' => 0,
            'path' => [(int) $root->id],
            'relation' => 'ROOT',
        ]];
        $visited = [];
        $issues = [];

        while ($queue !== []) {
            $entry = array_shift($queue);
            /** @var CostAllocation $allocation */
            $allocation = $entry['allocation'];
            $id = (int) $allocation->id;
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;
            $movement = $allocation->movement;
            $deltaValue = $entry['delta_value'];
            $quantity = BigDecimal::of((string) $allocation->quantity);
            $deltaUnitForNode = $quantity->isZero() ? BigDecimal::zero() : $deltaValue->dividedBy($quantity, 8, RoundingMode::HALF_UP);
            $nodeIssues = [];
            if (! $movement) {
                $nodeIssues[] = 'missing_movement';
            }
            if ($allocation->cost_status === 'PENDING') {
                $nodeIssues[] = 'pending_cost';
            }

            $rows[] = [
                'allocation_id' => $id,
                'parent_allocation_id' => $allocation->parent_allocation_id ? (int) $allocation->parent_allocation_id : null,
                'depth' => $entry['depth'],
                'business_date' => $movement?->business_date?->format('Y-m-d') ?: $allocation->business_date?->format('Y-m-d'),
                'source_type' => $movement?->source_type,
                'source_reference' => $movement?->source_reference,
                'warehouse_id' => (int) $allocation->warehouse_id,
                'stock_cost_layer_id' => $allocation->stock_cost_layer_id ? (int) $allocation->stock_cost_layer_id : null,
                'allocation_type' => (string) $allocation->allocation_type,
                'direction' => (string) $allocation->direction,
                'quantity' => (string) $allocation->quantity,
                'old_unit_cost' => (string) $allocation->unit_cost,
                'estimated_new_unit_cost' => $this->out(BigDecimal::of((string) $allocation->unit_cost)->plus($deltaUnitForNode)),
                'estimated_delta_value' => $this->out($deltaValue),
                'issues' => $nodeIssues,
                'lineage_relation' => $entry['relation'],
            ];

            foreach ($children->get($id, collect()) as $child) {
                $childDate = $child->movement?->business_date?->format('Y-m-d') ?: $child->business_date?->format('Y-m-d');
                if ($childDate && $childDate < $movementDate) {
                    $issues[] = ['allocation_id' => (int) $child->id, 'code' => 'child_before_source_date'];

                    continue;
                }
                if (in_array((int) $child->id, $entry['path'], true)) {
                    $issues[] = ['allocation_id' => (int) $child->id, 'code' => 'cycle'];

                    continue;
                }
                $parentQuantity = BigDecimal::of((string) $allocation->quantity);
                if ($parentQuantity->isZero()) {
                    $issues[] = ['allocation_id' => (int) $child->id, 'code' => 'zero_parent_quantity'];

                    continue;
                }
                $ratio = BigDecimal::of((string) $child->quantity)->dividedBy($parentQuantity, 12, RoundingMode::HALF_UP);
                $queue[] = [
                    'allocation' => $child,
                    'delta_value' => $deltaValue->multipliedBy($ratio),
                    'depth' => $entry['depth'] + 1,
                    'path' => [...$entry['path'], (int) $child->id],
                    'relation' => 'PARENT_ALLOCATION',
                ];
            }
        }

        $variance = $this->varianceReport($rows, $issues, $anchor, $method);

        return [
            'root' => [
                'allocation_id' => (int) $root->id,
                'business_date' => $movementDate,
                'source_type' => $root->movement?->source_type,
                'source_reference' => $root->movement?->source_reference,
                'current_unit_cost' => (string) $root->unit_cost,
                'proposed_unit_cost' => $proposedUnitCost,
                'delta_unit_cost' => $this->out($deltaUnit),
            ],
            'summary' => [
                'affected_nodes' => count($rows),
                'estimated_delta_value' => $this->out(collect($rows)->reduce(fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus(BigDecimal::of($row['estimated_delta_value'])), BigDecimal::zero())),
                'impact_date' => $movementDate,
                'calculation_date' => now()->format('Y-m-d'),
                'impact_end_date' => collect($rows)->pluck('business_date')->filter()->max() ?: $movementDate,
                'nodes_scanned' => $allocations->count(),
                'nodes_affected' => count($rows),
                'anchor_date' => $anchor['anchor_date'],
                'anchor_rows' => $anchor['rows'],
                'anchor_status' => $anchor['status'],
                'scope' => ['warehouse_id' => (int) $root->warehouse_id, 'item_id' => (int) $root->item_id, 'uom_id' => (int) $root->uom_id, 'method' => $method],
                ...$this->periodPolicy($movementDate),
                'calculation_method' => $method,
                'cost_policy' => $method === 'FIFO' ? 'รักษา Cost Layer และ split ตาม lineage' : ($method === 'AVG' ? 'กระจายผลต่างตาม AVG pool และ quantity ratio' : 'ยังไม่รู้จัก Costing Method'),
                'production_output_policy' => 'ตรวจ Production Receipt ผ่าน normalized bridge resolver',
            ],
            'valuation' => $this->valuationComparison($root, $rows),
            'fifo_evidence' => $method === 'FIFO' ? $this->fifoEvidence($rows) : [],
            'variance_report' => $variance,
            'rows' => $rows,
            'issues' => $issues,
            'read_only' => true,
        ];
    }

    /** Replay Transfer and Production bridge targets without mutating ledgers. */
    private function withBridgeReplays(array $result, int $limit): array
    {
        $frontier = $this->replayEdges($result, 1, [(int) ($result['root']['allocation_id'] ?? 0)]);
        $replays = [];
        $blockers = [];
        $nodesScanned = (int) ($result['summary']['nodes_scanned'] ?? 0);
        $maxPartitions = min(1000, $limit);

        while ($frontier !== []) {
            $ids = collect($frontier)->pluck('edge.child_allocation_id')->map(fn ($id): int => (int) $id)->filter()->unique()->values();
            $allocations = $ids->isEmpty() ? collect() : CostAllocation::query()
                ->with('movement:id,warehouse_id,source_type,source_reference,business_date')
                ->whereIn('id', $ids->all())
                ->get(['id', 'stock_movement_id', 'warehouse_id', 'item_id', 'uom_id', 'method', 'status', 'cost_status', 'journal_entry_id', 'quantity', 'unit_cost', 'value', 'business_date'])
                ->keyBy('id');
            $next = [];

            foreach ($frontier as $entry) {
                $edge = $entry['edge'];
                $childId = (int) $edge['child_allocation_id'];
                if ($entry['cycle'] ?? false) {
                    $blockers[] = "ALLOCATION_{$childId}:TRANSFER_REPLAY_CYCLE_DETECTED";

                    continue;
                }
                if ($entry['depth'] > 64) {
                    $blockers[] = "ALLOCATION_{$childId}:TRANSFER_REPLAY_MAX_DEPTH_REACHED";

                    continue;
                }
                if (count($replays) >= $maxPartitions) {
                    $blockers[] = 'TRANSFER_REPLAY_PARTITION_LIMIT_REACHED';

                    break 2;
                }
                if ($nodesScanned >= $limit) {
                    $blockers[] = 'TRANSFER_REPLAY_NODE_LIMIT_REACHED';

                    break 2;
                }

                /** @var CostAllocation|null $allocation */
                $allocation = $allocations->get($childId);
                if (! $allocation) {
                    $blockers[] = "ALLOCATION_{$childId}:TRANSFER_REPLAY_ROOT_MISSING";

                    continue;
                }
                $quantity = BigDecimal::of((string) $allocation->quantity);
                $newValue = BigDecimal::of((string) $allocation->value)->plus((string) $edge['estimated_delta_value']);
                if ($quantity->isLessThanOrEqualTo(0) || $newValue->isNegative()) {
                    $blockers[] = "ALLOCATION_{$childId}:TRANSFER_REPLAY_COST_INVALID";

                    continue;
                }
                $proposedUnitCost = $newValue->dividedBy($quantity, 8, RoundingMode::HALF_UP)->__toString();

                try {
                    $replay = $this->calculatePartition($childId, $proposedUnitCost, $limit - $nodesScanned);
                } catch (Throwable) {
                    $blockers[] = "ALLOCATION_{$childId}:TRANSFER_REPLAY_FAILED";

                    continue;
                }

                $nodesScanned += (int) ($replay['summary']['nodes_scanned'] ?? 0);
                foreach ($replay['variance_report']['blockers'] ?? [] as $blocker) {
                    $blockers[] = "ALLOCATION_{$childId}:{$blocker}";
                }
                $replays[] = [
                    'depth' => $entry['depth'],
                    'source_edge' => $edge,
                    'root' => $replay['root'],
                    'summary' => $replay['summary'],
                    'impact_summary' => $replay['impact_summary'] ?? null,
                    'valuation' => $replay['valuation'] ?? null,
                    'direct_bridges' => $replay['direct_bridges'] ?? [],
                    'rows' => $replay['rows'] ?? [],
                ];
                $replay = $this->withProductionBridges($replay);
                $replays[array_key_last($replays)] = [
                    ...$replays[array_key_last($replays)],
                    'production_bridges' => $replay['production_bridges'],
                ];
                $next = [...$next, ...$this->replayEdges($replay, $entry['depth'] + 1, [...$entry['path'], $childId])];
            }

            $frontier = $next;
        }

        $transferReplays = array_values(array_filter($replays, fn (array $replay): bool => ($replay['source_edge']['relation'] ?? null) === 'TRANSFER_ACCEPT'));
        $productionReplays = array_values(array_filter($replays, fn (array $replay): bool => ($replay['source_edge']['relation'] ?? null) === 'PRODUCTION_OUTPUT'));
        $result['bridge_replays'] = [
            'read_only' => true,
            'ready' => $blockers === [],
            'partitions' => $replays,
            'nodes_scanned' => $nodesScanned - (int) ($result['summary']['nodes_scanned'] ?? 0),
            'blockers' => array_values(array_unique($blockers)),
        ];
        $result['transfer_replays'] = [
            ...$result['bridge_replays'],
            'partitions' => $transferReplays,
            'nodes_scanned' => collect($transferReplays)->sum(fn (array $replay): int => (int) ($replay['summary']['nodes_scanned'] ?? 0)),
        ];
        $result['production_replays'] = [
            ...$result['bridge_replays'],
            'partitions' => $productionReplays,
            'nodes_scanned' => collect($productionReplays)->sum(fn (array $replay): int => (int) ($replay['summary']['nodes_scanned'] ?? 0)),
        ];
        $result['summary']['transfer_replay_partitions'] = count($transferReplays);
        $result['summary']['production_replay_partitions'] = count($productionReplays);
        $result['summary']['bridge_replay_nodes'] = $result['bridge_replays']['nodes_scanned'];
        $result['summary']['transfer_replay_nodes'] = $result['transfer_replays']['nodes_scanned'];
        $result['summary']['production_replay_nodes'] = $result['production_replays']['nodes_scanned'];
        $result['summary']['transfer_replay_affected_nodes'] = collect($transferReplays)->sum(fn (array $replay): int => (int) ($replay['summary']['nodes_affected'] ?? 0));
        $result['summary']['production_replay_affected_nodes'] = collect($productionReplays)->sum(fn (array $replay): int => (int) ($replay['summary']['nodes_affected'] ?? 0));
        $result['recursive_impact_summary'] = $this->recursiveImpactSummary($result, $replays);
        $result['variance_report']['blockers'] = array_values(array_unique([
            ...($result['variance_report']['blockers'] ?? []),
            ...$result['bridge_replays']['blockers'],
        ]));
        $result['variance_report']['status'] = $result['variance_report']['blockers'] === [] ? 'READY_FOR_REVIEW' : 'REQUIRES_REVIEW';
        $result['issues'] = $result['variance_report']['blockers'];

        return $result;
    }

    /** @return list<array{edge:array<string,mixed>,depth:int,path:list<int>,cycle?:bool}> */
    private function replayEdges(array $result, int $depth, array $path): array
    {
        $entries = [];
        $edges = collect([
            ...($result['direct_bridges']['edges'] ?? []),
            ...($result['production_bridges']['edges'] ?? []),
        ])->filter(fn (array $edge): bool => in_array($edge['relation'] ?? null, ['TRANSFER_ACCEPT', 'PRODUCTION_OUTPUT'], true)
            && (($edge['relation'] ?? null) === 'PRODUCTION_OUTPUT' || ($edge['cross_partition'] ?? false))
            && ($edge['issues'] ?? []) === [] && ($edge['estimated_delta_value'] ?? '0.00000000') !== '0.00000000')
            ->groupBy(fn (array $edge): string => ($edge['relation'] ?? '').':'.($edge['child_allocation_id'] ?? 0))
            ->map(function ($rows): array {
                $edge = $rows->first();
                $edge['estimated_delta_value'] = $this->out($rows->reduce(fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['estimated_delta_value']), BigDecimal::zero()));
                $edge['parent_allocation_ids'] = $rows->pluck('parent_allocation_id')->unique()->sort()->values()->all();

                return $edge;
            })->values();

        foreach ($edges as $edge) {
            if (($edge['relation'] ?? null) === 'TRANSFER_ACCEPT') {
                $edge['counted_in_source_open_bridge'] = (int) ($edge['parent_allocation_id'] ?? 0) !== (int) ($result['root']['allocation_id'] ?? 0);
            } else {
                $edge['counted_in_source_terminal'] = true;
            }
            $childId = (int) ($edge['child_allocation_id'] ?? 0);
            if ($childId < 1 || in_array($childId, $path, true)) {
                $entries[] = [
                    'edge' => $edge,
                    'depth' => $depth,
                    'path' => $path,
                    'cycle' => true,
                ];

                continue;
            }
            $entries[] = ['edge' => $edge, 'depth' => $depth, 'path' => $path];
        }

        return $entries;
    }

    private function withProductionBridges(array $result): array
    {
        $production = $this->productionBridges->resolve($result['rows'] ?? []);
        $result['production_bridges'] = $production;
        $result['summary']['production_bridge_edges'] = count($production['edges']);
        $result['summary']['production_bridge_partitions'] = count($production['partitions']);
        $result['summary']['production_output_policy'] = $production['edges'] === []
            ? 'ไม่พบ Production Receipt linkage ใน graph นี้'
            : 'กระจายผลต่างตามสัดส่วนมูลค่า output และปัด residual เข้ารายการสุดท้าย';
        $result['variance_report']['blockers'] = array_values(array_unique([
            ...($result['variance_report']['blockers'] ?? []),
            ...$production['blockers'],
        ]));

        return $result;
    }

    /** @param list<array<string,mixed>> $replays */
    private function recursiveImpactSummary(array $result, array $replays): array
    {
        $source = BigDecimal::of((string) ($result['impact_summary']['source_delta_value'] ?? '0'));
        $rootId = (int) ($result['root']['allocation_id'] ?? 0);
        $rootRow = collect($result['rows'] ?? [])->firstWhere('allocation_id', $rootId) ?? [];
        $rootBridge = ($rootRow['impact_bucket'] ?? null) === 'TRANSFER_BRIDGE' && ($rootRow['direction'] ?? null) === 'OUT'
            ? $source
            : BigDecimal::zero();
        if (! $rootBridge->isZero()) {
            $source = BigDecimal::zero();
        }
        $ending = BigDecimal::of((string) ($result['impact_summary']['ending_on_hand_delta_value'] ?? '0'));
        $terminal = BigDecimal::of((string) ($result['impact_summary']['terminal_delta_value'] ?? '0'));
        $bridges = BigDecimal::of((string) ($result['impact_summary']['open_bridge_delta_value'] ?? '0'));
        $replayed = BigDecimal::zero();
        $productionReplayed = BigDecimal::zero();

        foreach ($replays as $replay) {
            $impact = $replay['impact_summary'] ?? [];
            if ($replay['source_edge']['counted_in_source_open_bridge'] ?? false) {
                $replayed = $replayed->plus((string) ($replay['source_edge']['estimated_delta_value'] ?? '0'));
            }
            if ($replay['source_edge']['counted_in_source_terminal'] ?? false) {
                $productionReplayed = $productionReplayed->plus((string) ($replay['source_edge']['estimated_delta_value'] ?? '0'));
            }
            $ending = $ending->plus((string) ($impact['ending_on_hand_delta_value'] ?? '0'));
            $terminal = $terminal->plus((string) ($impact['terminal_delta_value'] ?? '0'));
            $bridges = $bridges->plus((string) ($impact['open_bridge_delta_value'] ?? '0'));
        }
        $bridges = $bridges->minus($replayed);
        $terminal = $terminal->minus($productionReplayed);

        return [
            'source_delta_value' => $this->out($source),
            'root_transfer_bridge_delta_value' => $this->out($rootBridge),
            'ending_on_hand_delta_value' => $this->out($ending),
            'terminal_delta_value' => $this->out($terminal),
            'open_bridge_delta_value' => $this->out($bridges),
            'replayed_transfer_delta_value' => $this->out($replayed),
            'replayed_production_delta_value' => $this->out($productionReplayed),
            'rounding_residual_value' => $this->out($source->minus($ending)->minus($terminal)->minus($bridges)),
        ];
    }

    public function allocationOptions(int $warehouseId, ?string $search = null, int $limit = 50): array
    {
        $allocations = CostAllocation::query()
            ->with('movement:id,source_type,source_id,source_reference,business_date')
            ->where('warehouse_id', $warehouseId)
            ->where('status', '!=', 'REVERSED')
            ->when($search, fn ($query) => $query->whereHas('movement', fn ($movement) => $movement
                ->where('source_reference', 'like', '%'.addcslashes($search, '%_').'%')
                ->orWhere('source_id', 'like', '%'.addcslashes($search, '%_').'%')
                ->orWhere(function ($movement) use ($search): void {
                    $movement->where('source_type', 'INVENTORY')->whereExists(function ($document) use ($search): void {
                        $document->from('wms_inventory_adjustments as adjustment_lines')
                            ->join('wms_inventory_adjustment_documents as adjustment_documents', 'adjustment_documents.id', '=', 'adjustment_lines.document_id')
                            ->whereColumn('adjustment_lines.stock_movement_id', 'wms_stock_movements.id')
                            ->where('adjustment_documents.document_number', 'like', '%'.addcslashes($search, '%_').'%');
                    });
                })))
            ->orderByDesc('id')
            ->limit(max(1, min($limit, 100)))
            ->get(['id', 'stock_movement_id', 'unit_cost', 'business_date']);
        $adjustmentNumbers = DB::table('wms_inventory_adjustments as adjustment_lines')
            ->join('wms_inventory_adjustment_documents as adjustment_documents', 'adjustment_documents.id', '=', 'adjustment_lines.document_id')
            ->whereIn('adjustment_lines.stock_movement_id', $allocations->pluck('stock_movement_id')->filter()->unique()->all())
            ->pluck('adjustment_documents.document_number', 'adjustment_lines.stock_movement_id');

        return $allocations->map(fn (CostAllocation $allocation): array => [
            'id' => (int) $allocation->id,
            'text' => '#'.$allocation->id.' · '.($adjustmentNumbers->get($allocation->stock_movement_id) ?: $allocation->movement?->source_reference ?: $allocation->movement?->source_type ?: 'Allocation'),
            'current_unit_cost' => (string) $allocation->unit_cost,
            'business_date' => $allocation->movement?->business_date?->format('Y-m-d') ?: $allocation->business_date?->format('Y-m-d'),
        ])->values()->all();
    }

    private function assertUnitCost(string $value): void
    {
        if (! preg_match('/^\d+(?:\.\d{1,8})?$/', $value)) {
            throw ValidationException::withMessages(['proposed_unit_cost' => 'ต้นทุนใหม่ต้องเป็นเลขทศนิยมไม่ติดลบ สูงสุด 8 ตำแหน่ง']);
        }
        BigDecimal::of($value)->toScale(8, RoundingMode::UNNECESSARY);
    }

    private function calculateAvg(CostAllocation $root, string $proposedUnitCost, string $impactDate, int $limit): array
    {
        $limit = max(1, min($limit, 10000));
        $cursor = null;
        $continuation = null;
        $anchor = null;
        $resolvedRows = [];
        $timelineRows = [];
        $blockers = [];
        $nodesScanned = 0;
        $rootSeen = false;
        $hasMore = false;

        do {
            $page = $this->timelines->read(
                (int) $root->warehouse_id,
                (int) $root->item_id,
                (int) $root->uom_id,
                'AVG',
                $impactDate,
                $cursor,
                min(1000, $limit - $nodesScanned),
            );
            $anchor ??= $page['anchor'];
            $nodesScanned += count($page['rows']);
            foreach ($page['rows'] as $row) {
                $timelineRows[(int) $row['allocation_id']] = $row;
            }

            $pageAnchor = $continuation ?? $page['anchor'];
            $overrides = collect($page['rows'])->contains(fn (array $row): bool => (int) $row['allocation_id'] === (int) $root->id)
                ? [(int) $root->id => ['unit_cost' => $proposedUnitCost]]
                : [];
            $resolved = $this->avgPool->resolve($pageAnchor, $page['rows'], $overrides);
            $resolvedRows = [...$resolvedRows, ...$resolved['rows']];
            $blockers = [...$blockers, ...$resolved['blockers']];
            $rootSeen = $rootSeen || $overrides !== [];
            $hasMore = (bool) $page['page']['has_more'];

            if (! $resolved['ready'] || ($rootSeen && $resolved['summary']['converged'])) {
                break;
            }

            $continuation = [
                'status' => 'READY',
                'blockers' => [],
                'old' => $resolved['summary']['ending_old'],
                'new' => $resolved['summary']['ending_new'],
                'propagation' => $resolved['summary']['propagation'],
            ];
            $cursor = $page['page']['next_cursor'];
        } while ($hasMore && $nodesScanned < $limit);

        if (! $rootSeen) {
            $blockers[] = 'ROOT_ALLOCATION_NOT_REACHED';
        }
        if ($hasMore && $nodesScanned >= $limit) {
            $blockers[] = 'TIMELINE_LIMIT_REACHED';
        }
        foreach ($timelineRows as $id => $timelineRow) {
            foreach ($timelineRow['warnings'] ?? [] as $warning) {
                $blockers[] = "ALLOCATION_{$id}:{$warning}";
            }
        }

        $rows = array_map(fn (array $row): array => $this->avgShadowRow($row, $timelineRows[(int) $row['allocation_id']] ?? []), $resolvedRows);
        $impact = $this->impactSummary($root, $resolvedRows);
        $direct = $this->directBridges->resolve($rows);
        $blockers = array_values(array_unique([...$blockers, ...$direct['blockers']]));
        $anchor ??= ['status' => 'MISSING', 'through_date' => null, 'rows' => 0];

        return [
            'calculation_contract_version' => 'cost-shadow-v2-avg-canonical-movement-quantity-legacy-proof',
            'root' => [
                'allocation_id' => (int) $root->id,
                'business_date' => $impactDate,
                'source_type' => $root->movement?->source_type,
                'source_reference' => $root->movement?->source_reference,
                'current_unit_cost' => (string) $root->unit_cost,
                'proposed_unit_cost' => $proposedUnitCost,
                'delta_unit_cost' => $this->out(BigDecimal::of($proposedUnitCost)->minus(BigDecimal::of((string) $root->unit_cost))),
            ],
            'summary' => [
                'affected_nodes' => count(array_filter($resolvedRows, fn (array $row): bool => $row['event']['delta_value'] !== '0.00000000')),
                'estimated_delta_value' => $impact['source_delta_value'],
                'impact_date' => $impactDate,
                'calculation_date' => now()->format('Y-m-d'),
                'impact_end_date' => collect($resolvedRows)->filter(fn (array $row): bool => $row['event']['delta_value'] !== '0.00000000')->pluck('business_date')->filter()->max() ?: $impactDate,
                'nodes_scanned' => $nodesScanned,
                'nodes_affected' => count(array_filter($resolvedRows, fn (array $row): bool => $row['event']['delta_value'] !== '0.00000000')),
                'direct_bridge_edges' => count($direct['edges']),
                'direct_bridge_partitions' => count($direct['partitions']),
                'anchor_date' => $anchor['through_date'] ?? null,
                'anchor_rows' => $anchor['rows'] ?? 0,
                'anchor_status' => $anchor['status'] ?? 'MISSING',
                'scope' => ['warehouse_id' => (int) $root->warehouse_id, 'item_id' => (int) $root->item_id, 'uom_id' => (int) $root->uom_id, 'method' => 'AVG'],
                ...$this->periodPolicy($impactDate),
                'calculation_method' => 'AVG',
                'cost_policy' => 'Replay AVG pool ตาม business date และ deterministic allocation order',
                'production_output_policy' => 'Production และ Transfer bridge จะส่งต่อข้าม partition ใน Direct Bridge phase',
            ],
            'impact_summary' => $impact,
            'direct_bridges' => $direct,
            'valuation' => $this->partitionValuation($root, $impact['ending_on_hand_delta_value'], 'AVG'),
            'fifo_evidence' => [],
            'variance_report' => [
                'status' => $blockers === [] ? 'READY_FOR_REVIEW' : 'REQUIRES_REVIEW',
                'blockers' => $blockers,
                'rows' => collect($rows)->map(fn (array $row): array => [
                    'allocation_id' => $row['allocation_id'],
                    'lineage_relation' => $row['lineage_relation'],
                    'estimated_delta_value' => $row['estimated_delta_value'],
                    'status' => $row['issues'] === [] ? 'OK' : 'REQUIRES_REVIEW',
                    'issues' => $row['issues'],
                ])->values()->all(),
            ],
            'rows' => $rows,
            'issues' => $blockers,
            'read_only' => true,
        ];
    }

    private function calculateFifo(CostAllocation $root, string $proposedUnitCost, string $impactDate, int $limit): array
    {
        $limit = max(1, min($limit, 10000));
        $cursor = null;
        $continuation = null;
        $anchor = null;
        $resolvedRows = [];
        $timelineRows = [];
        $blockers = [];
        $nodesScanned = 0;
        $rootSeen = false;
        $hasMore = false;

        do {
            $page = $this->timelines->read(
                (int) $root->warehouse_id,
                (int) $root->item_id,
                (int) $root->uom_id,
                'FIFO',
                $impactDate,
                $cursor,
                min(1000, $limit - $nodesScanned),
            );
            $anchor ??= $page['anchor'];
            $nodesScanned += count($page['rows']);
            foreach ($page['rows'] as $row) {
                $timelineRows[(int) $row['allocation_id']] = $row;
            }

            $pageAnchor = $continuation ?? $page['anchor'];
            $overrides = collect($page['rows'])->contains(fn (array $row): bool => (int) $row['allocation_id'] === (int) $root->id)
                ? [(int) $root->id => ['unit_cost' => $proposedUnitCost]]
                : [];
            $resolved = $this->fifoLayers->resolve($pageAnchor, $page['rows'], $overrides);
            $resolvedRows = [...$resolvedRows, ...$resolved['rows']];
            $blockers = [...$blockers, ...$resolved['blockers']];
            $rootSeen = $rootSeen || $overrides !== [];
            $hasMore = (bool) $page['page']['has_more'];

            if (! $resolved['ready'] || ($rootSeen && $resolved['summary']['converged'])) {
                break;
            }

            $continuation = [
                'status' => 'READY',
                'blockers' => [],
                'old' => ['layers' => $resolved['summary']['old_layers']],
                'new' => ['layers' => $resolved['summary']['new_layers']],
                'propagation' => $resolved['summary']['propagation'],
            ];
            $cursor = $page['page']['next_cursor'];
        } while ($hasMore && $nodesScanned < $limit);

        if (! $rootSeen) {
            $blockers[] = 'ROOT_ALLOCATION_NOT_REACHED';
        }
        if ($hasMore && $nodesScanned >= $limit) {
            $blockers[] = 'TIMELINE_LIMIT_REACHED';
        }
        foreach ($timelineRows as $id => $timelineRow) {
            foreach ($timelineRow['warnings'] ?? [] as $warning) {
                $blockers[] = "ALLOCATION_{$id}:{$warning}";
            }
        }

        $rows = array_map(fn (array $row): array => $this->fifoShadowRow($row, $timelineRows[(int) $row['allocation_id']] ?? []), $resolvedRows);
        $impact = $this->impactSummary($root, $resolvedRows);
        $direct = $this->directBridges->resolve($rows);
        $blockers = array_values(array_unique([...$blockers, ...$direct['blockers']]));
        $anchor ??= ['status' => 'MISSING', 'through_date' => null, 'rows' => 0];

        return [
            'calculation_contract_version' => 'cost-shadow-v2-fifo',
            'root' => [
                'allocation_id' => (int) $root->id,
                'business_date' => $impactDate,
                'source_type' => $root->movement?->source_type,
                'source_reference' => $root->movement?->source_reference,
                'current_unit_cost' => (string) $root->unit_cost,
                'proposed_unit_cost' => $proposedUnitCost,
                'delta_unit_cost' => $this->out(BigDecimal::of($proposedUnitCost)->minus(BigDecimal::of((string) $root->unit_cost))),
            ],
            'summary' => [
                'affected_nodes' => count(array_filter($resolvedRows, fn (array $row): bool => $row['event']['delta_value'] !== '0.00000000')),
                'estimated_delta_value' => $impact['source_delta_value'],
                'impact_date' => $impactDate,
                'calculation_date' => now()->format('Y-m-d'),
                'impact_end_date' => collect($resolvedRows)->filter(fn (array $row): bool => $row['event']['delta_value'] !== '0.00000000')->pluck('business_date')->filter()->max() ?: $impactDate,
                'nodes_scanned' => $nodesScanned,
                'nodes_affected' => count(array_filter($resolvedRows, fn (array $row): bool => $row['event']['delta_value'] !== '0.00000000')),
                'direct_bridge_edges' => count($direct['edges']),
                'direct_bridge_partitions' => count($direct['partitions']),
                'anchor_date' => $anchor['through_date'] ?? null,
                'anchor_rows' => $anchor['rows'] ?? 0,
                'anchor_status' => $anchor['status'] ?? 'MISSING',
                'scope' => ['warehouse_id' => (int) $root->warehouse_id, 'item_id' => (int) $root->item_id, 'uom_id' => (int) $root->uom_id, 'method' => 'FIFO'],
                ...$this->periodPolicy($impactDate),
                'calculation_method' => 'FIFO',
                'cost_policy' => 'Replay FIFO ตาม Cost Layer และ deterministic allocation order',
                'production_output_policy' => 'Production และ Transfer bridge จะส่งต่อข้าม partition ใน Direct Bridge phase',
            ],
            'impact_summary' => $impact,
            'direct_bridges' => $direct,
            'valuation' => $this->partitionValuation($root, $impact['ending_on_hand_delta_value'], 'FIFO'),
            'fifo_evidence' => $this->fifoEvidence($rows),
            'variance_report' => [
                'status' => $blockers === [] ? 'READY_FOR_REVIEW' : 'REQUIRES_REVIEW',
                'blockers' => $blockers,
                'rows' => collect($rows)->map(fn (array $row): array => [
                    'allocation_id' => $row['allocation_id'],
                    'lineage_relation' => $row['lineage_relation'],
                    'estimated_delta_value' => $row['estimated_delta_value'],
                    'status' => $row['issues'] === [] ? 'OK' : 'REQUIRES_REVIEW',
                    'issues' => $row['issues'],
                ])->values()->all(),
            ],
            'rows' => $rows,
            'issues' => $blockers,
            'read_only' => true,
        ];
    }

    /** @param array<string, mixed> $resolved @param array<string, mixed> $timeline */
    private function avgShadowRow(array $resolved, array $timeline): array
    {
        $quantity = BigDecimal::of($resolved['event']['quantity']);
        $newValue = BigDecimal::of($resolved['event']['new_value'])->abs();
        $newUnitCost = $quantity->isZero() ? BigDecimal::zero() : $newValue->dividedBy($quantity, 8, RoundingMode::HALF_UP);
        $evidence = $resolved['impact']['evidence'] ?? [];

        return [
            'allocation_id' => $resolved['allocation_id'],
            'parent_allocation_id' => $resolved['parent_allocation_id'],
            'depth' => 0,
            'business_date' => $resolved['business_date'],
            'source_type' => $evidence['source_type'] ?? null,
            'source_reference' => $evidence['source_reference'] ?? null,
            'warehouse_id' => $resolved['impact']['warehouse_id'] ?? null,
            'item_id' => $resolved['impact']['item_id'] ?? null,
            'uom_id' => $resolved['impact']['uom_id'] ?? null,
            'method' => 'AVG',
            'stock_cost_layer_id' => null,
            'allocation_type' => $resolved['event']['allocation_type'],
            'direction' => $resolved['event']['direction'],
            'quantity' => $resolved['event']['quantity'],
            'old_unit_cost' => $resolved['old_unit_cost'],
            'estimated_new_unit_cost' => $this->out($newUnitCost),
            'estimated_delta_value' => $resolved['event']['delta_value'],
            'impact_bucket' => $resolved['impact_bucket'],
            'target_event' => $resolved['impact']['target_event'] ?? null,
            'old_pool_after' => $resolved['after']['old'],
            'new_pool_after' => $resolved['after']['new'],
            'issues' => array_values(array_unique([...(array) ($timeline['blockers'] ?? []), ...(array) ($timeline['warnings'] ?? [])])),
            'lineage_relation' => 'AVG_TIMELINE',
        ];
    }

    /** @param array<string, mixed> $resolved @param array<string, mixed> $timeline */
    private function fifoShadowRow(array $resolved, array $timeline): array
    {
        $quantity = BigDecimal::of($resolved['event']['quantity']);
        $newValue = BigDecimal::of($resolved['event']['new_value'])->abs();
        $newUnitCost = $quantity->isZero() ? BigDecimal::zero() : $newValue->dividedBy($quantity, 8, RoundingMode::HALF_UP);
        $evidence = $resolved['impact']['evidence'] ?? [];

        return [
            'allocation_id' => $resolved['allocation_id'],
            'parent_allocation_id' => $resolved['parent_allocation_id'],
            'depth' => 0,
            'business_date' => $resolved['business_date'],
            'source_type' => $evidence['source_type'] ?? null,
            'source_reference' => $evidence['source_reference'] ?? null,
            'warehouse_id' => $resolved['impact']['warehouse_id'] ?? null,
            'item_id' => $resolved['impact']['item_id'] ?? null,
            'uom_id' => $resolved['impact']['uom_id'] ?? null,
            'method' => 'FIFO',
            'stock_cost_layer_id' => $resolved['stock_cost_layer_id'],
            'allocation_type' => $resolved['event']['allocation_type'],
            'direction' => $resolved['event']['direction'],
            'quantity' => $resolved['event']['quantity'],
            'old_unit_cost' => (string) ($timeline['unit_cost'] ?? '0.00000000'),
            'estimated_new_unit_cost' => $this->out($newUnitCost),
            'estimated_delta_value' => $resolved['event']['delta_value'],
            'impact_bucket' => $resolved['impact_bucket'],
            'target_event' => $resolved['impact']['target_event'] ?? null,
            'old_pool_after' => $resolved['after']['old'],
            'new_pool_after' => $resolved['after']['new'],
            'issues' => array_values(array_unique([...(array) ($timeline['blockers'] ?? []), ...(array) ($timeline['warnings'] ?? [])])),
            'lineage_relation' => 'FIFO_LAYER_TIMELINE',
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function impactSummary(CostAllocation $root, array $rows): array
    {
        $source = BigDecimal::zero();
        $terminal = BigDecimal::zero();
        $bridges = BigDecimal::zero();
        $terminalByBucket = [];
        $bridgeByBucket = [];
        $ending = BigDecimal::zero();
        $terminalBuckets = ['COGS_CONSUMED', 'ISSUE_EXPENSE_CONSUMED', 'WIP_CONSUMED', 'PURCHASE_RETURN_CONSUMED'];
        $bridgeBuckets = ['FINISHED_GOODS_BRIDGE', 'TRANSFER_BRIDGE'];

        foreach ($rows as $row) {
            $delta = BigDecimal::of($row['event']['delta_value']);
            $ending = BigDecimal::of($row['after']['new']['value'])->minus(BigDecimal::of($row['after']['old']['value']));
            if ((int) $row['allocation_id'] === (int) $root->id) {
                $source = $delta;

                continue;
            }
            $bucket = $row['impact_bucket'];
            if (in_array($bucket, $terminalBuckets, true)) {
                $amount = $row['event']['direction'] === 'OUT' ? $delta->negated() : $delta;
                $terminal = $terminal->plus($amount);
                $terminalByBucket[$bucket] = $this->out(BigDecimal::of($terminalByBucket[$bucket] ?? '0')->plus($amount));
            } elseif ($bucket === 'RETURN_BRIDGE') {
                $amount = $delta->negated();
                $terminal = $terminal->plus($amount);
                $terminalByBucket[$bucket] = $this->out(BigDecimal::of($terminalByBucket[$bucket] ?? '0')->plus($amount));
            } elseif (in_array($bucket, $bridgeBuckets, true)) {
                $amount = $delta->negated();
                $bridges = $bridges->plus($amount);
                $bridgeByBucket[$bucket] = $this->out(BigDecimal::of($bridgeByBucket[$bucket] ?? '0')->plus($amount));
            }
        }

        $residual = $source->minus($ending)->minus($terminal)->minus($bridges);

        return [
            'source_delta_value' => $this->out($source),
            'ending_on_hand_delta_value' => $this->out($ending),
            'terminal_delta_value' => $this->out($terminal),
            'terminal_by_bucket' => $terminalByBucket,
            'open_bridge_delta_value' => $this->out($bridges),
            'open_bridge_by_bucket' => $bridgeByBucket,
            'rounding_residual_value' => $this->out($residual),
            'reconciliation_difference' => '0.00000000',
        ];
    }

    private function partitionValuation(CostAllocation $root, string $endingDelta, string $method): array
    {
        $balance = StockBalance::query()
            ->where('warehouse_id', $root->warehouse_id)
            ->where('item_id', $root->item_id)
            ->where('uom_id', $root->uom_id)
            ->first(['inventory_value']);
        $current = BigDecimal::of((string) ($balance?->inventory_value ?? '0'));
        $delta = BigDecimal::of($endingDelta);

        return [
            'warehouse_id' => (int) $root->warehouse_id,
            'item_id' => (int) $root->item_id,
            'current_inventory_value' => $this->out($current),
            'estimated_inventory_delta' => $this->out($delta),
            'estimated_inventory_value_after_delta' => $this->out($current->plus($delta)),
            'basis' => "Ending {$method} inventory delta ของ partition ต้นทาง; ยังไม่รวม open bridge ข้าม partition",
        ];
    }

    private function out(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }

    private function periodPolicy(string $impactDate): array
    {
        $period = FiscalPeriod::query()->where('start_date', '<=', $impactDate)->where('end_date', '>=', $impactDate)->first();
        if (! $period) {
            return ['posting_date' => null, 'period_status' => 'MISSING', 'posting_policy' => 'ไม่พบงวดบัญชีของวันที่มีผล'];
        }
        if ($period->status === 'OPEN') {
            return ['posting_date' => $impactDate, 'period_status' => 'OPEN', 'posting_policy' => 'งวดเปิด: หากอนุมัติ สามารถลงบัญชีตามวันที่มีผลได้'];
        }
        $nextOpen = FiscalPeriod::query()->where('status', 'OPEN')->where('start_date', '>', $impactDate)->orderBy('start_date')->value('start_date');

        return ['posting_date' => $nextOpen, 'period_status' => (string) $period->status, 'posting_policy' => $nextOpen ? 'งวดปิด: ต้องสร้าง Revaluation ในงวดเปิดถัดไป' : 'งวดปิดและยังไม่พบงวดเปิดถัดไป'];
    }

    private function historicalAnchor(CostAllocation $root, string $impactDate): array
    {
        if (! Schema::hasTable('wms_stock_cost_layers')) {
            return ['status' => 'MISSING', 'anchor_date' => null, 'rows' => 0];
        }

        $layers = StockCostLayer::query()
            ->where('warehouse_id', $root->warehouse_id)
            ->where('item_id', $root->item_id)
            ->where('uom_id', $root->uom_id)
            ->where('cost_status', 'FINAL')
            ->where('remaining_quantity', '>', 0)
            ->where('business_date', '<', $impactDate)
            ->orderByDesc('business_date')
            ->orderByDesc('id')
            ->limit(1000)
            ->get(['id', 'business_date', 'remaining_quantity', 'unit_cost']);

        return [
            'status' => $layers->isEmpty() ? 'REQUIRES_REBUILD' : 'READY',
            'anchor_date' => $layers->max('business_date')?->format('Y-m-d'),
            'rows' => $layers->count(),
        ];
    }

    private function valuationComparison(CostAllocation $root, array $rows): array
    {
        $balance = StockBalance::query()->where('warehouse_id', $root->warehouse_id)->where('item_id', $root->item_id)->where('uom_id', $root->uom_id)->first();
        $delta = collect($rows)->reduce(function (BigDecimal $sum, array $row) use ($root): BigDecimal {
            if ((int) $row['warehouse_id'] !== (int) $root->warehouse_id) {
                return $sum;
            }

            $value = BigDecimal::of($row['estimated_delta_value']);

            return $sum->plus($row['direction'] === 'OUT' ? $value->negated() : $value);
        }, BigDecimal::zero());
        $current = BigDecimal::of((string) ($balance?->inventory_value ?? '0'));

        return [
            'warehouse_id' => (int) $root->warehouse_id,
            'item_id' => (int) $root->item_id,
            'current_inventory_value' => $this->out($current),
            'estimated_inventory_delta' => $this->out($delta),
            'estimated_inventory_value_after_delta' => $this->out($current->plus($delta)),
            'basis' => 'ประมาณการจาก affected allocation ในคลังต้นทางเท่านั้น; ยังไม่ใช่การ Apply จริง',
        ];
    }

    private function fifoEvidence(array $rows): array
    {
        $layerIds = collect($rows)->pluck('stock_cost_layer_id')->filter()->unique()->values();
        if ($layerIds->isEmpty() || ! Schema::hasTable('wms_stock_cost_layers')) {
            return [];
        }

        return StockCostLayer::query()->whereIn('id', $layerIds->all())
            ->get(['id', 'source_movement_id', 'business_date', 'remaining_quantity', 'unit_cost', 'cost_status'])
            ->map(fn (StockCostLayer $layer): array => [
                'layer_id' => (int) $layer->id,
                'source_movement_id' => (int) $layer->source_movement_id,
                'business_date' => $layer->business_date?->format('Y-m-d'),
                'remaining_quantity' => (string) $layer->remaining_quantity,
                'unit_cost' => (string) $layer->unit_cost,
                'cost_status' => (string) $layer->cost_status,
            ])->values()->all();
    }

    private function varianceReport(array $rows, array $issues, array $anchor, string $method): array
    {
        $blockers = [];
        if ($method !== 'AVG' && $method !== 'FIFO') {
            $blockers[] = 'UNSUPPORTED_COST_METHOD';
        }
        if (in_array($anchor['status'], ['MISSING', 'REQUIRES_REBUILD'], true)) {
            $blockers[] = 'HISTORICAL_ANCHOR_MISSING';
        }
        if ($issues !== []) {
            $blockers[] = 'LINEAGE_ISSUES';
        }
        if (collect($rows)->contains(fn (array $row): bool => in_array('pending_cost', $row['issues'], true))) {
            $blockers[] = 'PENDING_COST';
        }

        return [
            'status' => $blockers === [] ? 'READY_FOR_REVIEW' : 'REQUIRES_REVIEW',
            'blockers' => array_values(array_unique($blockers)),
            'rows' => collect($rows)->map(fn (array $row): array => [
                'allocation_id' => $row['allocation_id'],
                'lineage_relation' => $row['lineage_relation'],
                'estimated_delta_value' => $row['estimated_delta_value'],
                'status' => $row['issues'] === [] ? 'OK' : 'REQUIRES_REVIEW',
                'issues' => $row['issues'],
            ])->values()->all(),
        ];
    }
}
