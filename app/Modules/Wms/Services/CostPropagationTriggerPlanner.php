<?php

namespace App\Modules\Wms\Services;

use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesReturn;
use App\Modules\Pos\Models\SalesReturnInventoryLink;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\LandedCost;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Wms\Models\OpeningBalanceBatch;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Models\Transfer;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/** Builds a deterministic, read-only fan-out plan. It never writes or dispatches. */
final class CostPropagationTriggerPlanner
{
    public function __construct(
        private readonly EffectiveDocumentDateResolver $dates,
        private readonly CostImpactClassifier $impacts,
    ) {}

    /** @return array<string, mixed> */
    public function plan(string $documentType, int $documentId, int $revision = 0): array
    {
        $source = $this->source(strtoupper(trim($documentType)), $documentId, $revision);
        $rootLimit = max(1, (int) config('erp.inventory.revaluation_calculation_node_budget', 1000));
        $allocationLines = $source['allocation_lines'] ?? [];
        $allocationQuery = CostAllocation::query()
            ->with(['movement.warehouse:id,branch_id'])
            ->where('status', '!=', 'REVERSED')
            ->whereNotExists(fn ($correction) => $correction
                ->selectRaw('1')
                ->from('wms_cost_allocation_corrections')
                ->whereColumn('wms_cost_allocation_corrections.allocation_id', 'wms_cost_allocations.id'))
            ->orderBy('id');
        if ($allocationLines !== []) {
            $allocationQuery->whereIn('id', array_keys($allocationLines));
        } else {
            $allocationQuery->whereIn('stock_movement_id', array_keys($source['movement_lines']));
        }
        $allocations = $allocationQuery
            ->limit($rootLimit + 1)
            ->get([
                'id', 'stock_movement_id', 'warehouse_id', 'item_id', 'uom_id',
                'direction', 'cost_status', 'status', 'method', 'quantity',
                'business_date', 'journal_entry_id',
            ]);

        if ($allocations->count() > $rootLimit) {
            $source['blockers'][] = 'TRIGGER_ROOT_LIMIT_REACHED';
            $allocations = $allocations->take($rootLimit);
        }
        $rows = $allocations->map(function (CostAllocation $allocation) use ($source): array {
            $date = isset($source['force_effective_date'])
                ? ['effective_date' => $source['force_effective_date'], 'blockers' => []]
                : $this->dates->resolve($allocation);
            $impact = $this->impacts->classify(
                $allocation,
                '0',
                $source['branch_id'],
                'ROOT_DOCUMENT',
            );
            $method = strtoupper(trim((string) $allocation->method));
            $blockers = array_values(array_unique([...$date['blockers'], ...$impact->blockers]));
            if ($method === '') {
                $blockers[] = 'PARTITION_METHOD_MISSING';
            }

            return [
                'line_id' => ($source['allocation_lines'] ?? [])[(int) $allocation->id]
                    ?? $source['movement_lines'][(int) $allocation->stock_movement_id]
                    ?? null,
                'allocation_id' => (int) $allocation->id,
                'movement_id' => (int) $allocation->stock_movement_id,
                'warehouse_id' => (int) $allocation->warehouse_id,
                'branch_id' => $impact->branchId,
                'item_id' => (int) $allocation->item_id,
                'uom_id' => (int) $allocation->uom_id,
                'method' => $method,
                'effective_date' => $date['effective_date'],
                'impact' => $impact->toArray(),
                'status' => (string) $allocation->status,
                'cost_status' => (string) $allocation->cost_status,
                'journal_entry_id' => $allocation->journal_entry_id ? (int) $allocation->journal_entry_id : null,
                'blockers' => $blockers,
            ];
        })->all();

        return $this->compile($source, $rows);
    }

    /**
     * Pure deterministic fan-out used by automatic/manual previews and tests.
     *
     * @param  array<string, mixed>  $source
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public function compile(array $source, array $rows): array
    {
        $expectedLines = collect($source['line_ids'] ?? [])->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $proofIdentity = collect($rows)->sortBy('allocation_id')->map(fn (array $row): string => implode(':', [
            (int) ($row['allocation_id'] ?? 0),
            (string) ($row['status'] ?? ''),
            (string) ($row['cost_status'] ?? ''),
            (int) ($row['journal_entry_id'] ?? 0),
            (string) ($row['business_date'] ?? ''),
        ]))->implode('|');
        $triggerIdentity = hash('sha256', implode('|', [
            strtoupper((string) ($source['document_type'] ?? '')),
            (int) ($source['document_id'] ?? 0),
            (int) ($source['revision'] ?? 0),
            $proofIdentity,
        ]));

        $rows = collect($rows)->sortBy([
            ['warehouse_id', 'asc'], ['item_id', 'asc'], ['uom_id', 'asc'],
            ['method', 'asc'], ['movement_id', 'asc'], ['allocation_id', 'asc'],
        ])->values();
        $resolvedLines = $rows->pluck('line_id')->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $blockers = collect($source['blockers'] ?? []);

        if ($expectedLines->isEmpty()) {
            $blockers->push('SOURCE_HAS_NO_STOCK_LINES');
        }
        if (empty($source['document_date'])) {
            $blockers->push('SOURCE_DOCUMENT_DATE_MISSING');
        }
        foreach ($expectedLines->diff($resolvedLines) as $lineId) {
            $blockers->push("ROOT_LINE_MISSING:{$lineId}");
        }
        foreach ($resolvedLines->diff($expectedLines) as $lineId) {
            $blockers->push("ROOT_LINE_UNEXPECTED:{$lineId}");
        }
        if (($source['allocation_lines'] ?? []) !== []) {
            $resolvedAllocations = $rows->pluck('allocation_id')->map(fn ($id): int => (int) $id)->unique();
            foreach (collect(array_keys($source['allocation_lines']))->map(fn ($id): int => (int) $id)->diff($resolvedAllocations)->sort() as $allocationId) {
                $blockers->push("ROOT_ALLOCATION_MISSING:{$allocationId}");
            }
        } else {
            $resolvedMovements = $rows->pluck('movement_id')->map(fn ($id): int => (int) $id)->unique();
            foreach (collect(array_keys($source['movement_lines'] ?? []))->map(fn ($id): int => (int) $id)->diff($resolvedMovements)->sort() as $movementId) {
                $blockers->push("ROOT_ALLOCATION_MISSING:{$movementId}");
            }
        }
        foreach ($rows as $row) {
            foreach ($row['blockers'] ?? [] as $blocker) {
                $blockers->push("ALLOCATION_{$row['allocation_id']}:{$blocker}");
            }
        }

        $partitions = $rows->groupBy(fn (array $row): string => implode(':', [
            (int) $row['warehouse_id'], (int) $row['item_id'], (int) $row['uom_id'], strtoupper((string) $row['method']),
        ]))->map(function ($partitionRows, string $key) use ($triggerIdentity): array {
            $first = $partitionRows->first();
            $partitionBlockers = $partitionRows->flatMap(fn (array $row): array => $row['blockers'] ?? [])->unique()->values()->all();

            return [
                'partition_key' => $key,
                'child_identity' => hash('sha256', "{$triggerIdentity}|{$key}"),
                'warehouse_id' => (int) $first['warehouse_id'],
                'branch_id' => isset($first['branch_id']) ? (int) $first['branch_id'] : null,
                'item_id' => (int) $first['item_id'],
                'uom_id' => (int) $first['uom_id'],
                'method' => strtoupper((string) $first['method']),
                'root_line_ids' => $partitionRows->pluck('line_id')->filter()->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all(),
                'root_movement_ids' => $partitionRows->pluck('movement_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all(),
                'root_allocation_ids' => $partitionRows->pluck('allocation_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all(),
                'effective_start_date' => $partitionRows->pluck('effective_date')->filter()->sort()->first(),
                'blockers' => $partitionBlockers,
                'ready' => $partitionBlockers === [],
            ];
        })->sortKeys()->values()->all();

        $blockers = $blockers->unique()->sort()->values()->all();

        return [
            'read_only' => true,
            'dispatch' => false,
            'source' => [
                'document_type' => strtoupper((string) ($source['document_type'] ?? '')),
                'document_id' => (int) ($source['document_id'] ?? 0),
                'document_reference' => $source['document_reference'] ?? null,
                'document_date' => $source['document_date'] ?? null,
                'status' => $source['status'] ?? null,
                'revision' => (int) ($source['revision'] ?? 0),
                'trigger_identity' => $triggerIdentity,
            ],
            'summary' => [
                'expected_root_lines' => $expectedLines->count(),
                'resolved_root_lines' => $resolvedLines->intersect($expectedLines)->count(),
                'root_allocations' => $rows->count(),
                'expected_partitions' => count($partitions),
                'ready' => $blockers === [],
            ],
            'partitions' => $partitions,
            'blockers' => $blockers,
        ];
    }

    /** @return array<string, mixed> */
    private function source(string $type, int $id, int $revision): array
    {
        if ($id < 1 || $revision < 0) {
            throw new InvalidArgumentException('Document id must be positive and revision cannot be negative.');
        }

        return match ($type) {
            'OPENING_BALANCE' => $this->opening(OpeningBalanceBatch::query()->with('lines')->findOrFail($id), $revision),
            'INVENTORY_ADJUSTMENT' => $this->adjustment(InventoryAdjustmentDocument::query()->with('lines')->findOrFail($id), $revision, 'INVENTORY_ADJUSTMENT'),
            'PRODUCTION_FINISHED_RECEIPT' => $this->adjustment(InventoryAdjustmentDocument::query()->with('lines')->findOrFail($id), $revision, 'PRODUCTION_FINISHED_RECEIPT'),
            'ISSUE_DOCUMENT' => $this->issue(IssueDocument::withTrashed()->with('lines')->findOrFail($id), $revision),
            'ISSUE_RETURN' => $this->issueReturn(IssueReturn::withTrashed()->with('lines.sourceAllocations')->findOrFail($id), $revision),
            'WMS_TRANSFER' => $this->transfer(Transfer::withTrashed()->with(['lines', 'events'])->findOrFail($id), $revision),
            'GOODS_RECEIPT' => $this->metadataSource(GoodsReceipt::query()->with('lines')->findOrFail($id), $type, $revision, 'receipt_number', 'business_date', 'goods_receipt_line_id', 'GOODS_RECEIPT'),
            'PURCHASE_DOCUMENT' => $this->purchaseDocument(PurchaseDocument::query()->with(['lines.item'])->findOrFail($id), $revision),
            'PURCHASE_CREDIT_RETURN' => $this->purchaseCreditReturn(PurchaseDocument::query()->with(['lines.item'])->findOrFail($id), $revision),
            'PURCHASE_RETURN' => $this->purchaseReturn(PurchaseReturn::query()->with('lines')->findOrFail($id), $revision),
            'LANDED_COST' => $this->landedCost(LandedCost::query()->findOrFail($id), $revision),
            'PHYSICAL_SALE' => $this->metadataSource(PhysicalSale::query()->with(['lines.item'])->findOrFail($id), $type, $revision, 'document_number', 'document_date', 'physical_sale_line_id', 'POS', true),
            'SALES_RETURN' => $this->salesReturn(SalesReturn::query()->with('lines')->findOrFail($id), $revision),
            default => throw new InvalidArgumentException("Unsupported cost propagation document type: {$type}"),
        };
    }

    /** @return array<string, mixed> */
    private function opening(OpeningBalanceBatch $document, int $revision): array
    {
        return $this->descriptor($document, 'OPENING_BALANCE', $revision, 'source_reference', 'cutover_date',
            $document->lines->pluck('id')->all(), $document->lines->pluck('id', 'stock_movement_id')->filter(fn ($line, $movement) => (int) $movement > 0)->all());
    }

    /** @return array<string, mixed> */
    private function adjustment(InventoryAdjustmentDocument $document, int $revision, string $type): array
    {
        $movementField = $revision > 0 ? 'reversal_movement_id' : 'stock_movement_id';

        return $this->descriptor($document, $type, $revision, 'document_number', $revision > 0 ? 'reversed_at' : 'document_date',
            $document->lines->pluck('id')->all(), $document->lines->pluck('id', $movementField)->filter(fn ($line, $movement) => (int) $movement > 0)->all());
    }

    /** @return array<string, mixed> */
    private function issue(IssueDocument $document, int $revision): array
    {
        return $this->descriptor($document, 'ISSUE_DOCUMENT', $revision, 'document_number', 'document_date',
            $document->lines->pluck('id')->all(), $document->lines->pluck('id', 'stock_movement_id')->filter(fn ($line, $movement) => (int) $movement > 0)->all());
    }

    /** @return array<string, mixed> */
    private function issueReturn(IssueReturn $document, int $revision): array
    {
        $movementLines = [];
        if ($revision > 0) {
            $parentLines = $document->lines->flatMap(fn ($line) => $line->sourceAllocations
                ->filter(fn ($split): bool => (int) $split->cost_allocation_id > 0)
                ->mapWithKeys(fn ($split): array => [(int) $split->cost_allocation_id => (int) $line->id]));
            CostAllocation::query()->whereIn('parent_allocation_id', $parentLines->keys()->all())
                ->where('status', '!=', 'REVERSED')->orderBy('id')
                ->get(['id', 'stock_movement_id', 'parent_allocation_id'])
                ->each(function (CostAllocation $allocation) use (&$movementLines, $parentLines): void {
                    $lineId = (int) $parentLines->get((int) $allocation->parent_allocation_id);
                    if ($lineId > 0 && (int) $allocation->stock_movement_id > 0) {
                        $movementLines[(int) $allocation->stock_movement_id] = $lineId;
                    }
                });

            return $this->descriptor($document, 'ISSUE_RETURN', $revision, 'document_number', 'document_date', $document->lines->pluck('id')->all(), $movementLines);
        }
        foreach ($document->lines as $line) {
            foreach ($line->sourceAllocations as $split) {
                if ((int) $split->stock_movement_id > 0) {
                    $movementLines[(int) $split->stock_movement_id] = (int) $line->id;
                }
            }
            if ($revision === 0 && (int) $line->stock_movement_id > 0) {
                $movementLines[(int) $line->stock_movement_id] = (int) $line->id;
            }
        }

        return $this->descriptor($document, 'ISSUE_RETURN', $revision, 'document_number', 'document_date', $document->lines->pluck('id')->all(), $movementLines);
    }

    /** @return array<string, mixed> */
    private function transfer(Transfer $document, int $revision): array
    {
        $movementLines = $document->events->pluck('transfer_line_id', 'stock_movement_id')->filter(fn ($line, $movement) => (int) $movement > 0)->all();

        $source = $this->descriptor($document, 'WMS_TRANSFER', $revision, 'document_number', 'document_date', $document->lines->pluck('id')->all(), $movementLines);
        if ($revision > 0 && ($eventDate = $document->events->sortByDesc('id')->first()?->business_date)) {
            $source['document_date'] = $eventDate->format('Y-m-d');
        }

        return $source;
    }

    /** @return array<string, mixed> */
    private function purchaseDocument(PurchaseDocument $document, int $revision): array
    {
        $lines = $document->lines->filter(fn ($line): bool => (bool) $line->item?->is_stock_item);
        $originals = StockMovement::query()->where('source_type', 'PURCHASING')
            ->where('source_id', (string) $document->id)->where('source_reference', $document->document_number)
            ->whereNull('metadata->reversal_of_movement_id')->orderBy('id')
            ->limit(max(1, $lines->count()) + 1)->get(['id', 'business_date', 'metadata']);
        $movements = $revision === 0
            ? $originals
            : StockMovement::query()->where('source_type', 'PURCHASING')
                ->whereIn('source_id', $originals->pluck('id')->map(fn ($id): string => (string) $id)->all())
                ->whereNotNull('metadata->reversal_of_movement_id')->orderBy('id')
                ->limit(max(1, $lines->count()) + 1)->get(['id', 'business_date', 'metadata']);
        $movementLines = $movements->mapWithKeys(function (StockMovement $movement) use ($lines): array {
            $lineId = (int) data_get($movement->metadata, 'purchase_line_id');

            return $lineId > 0 && $lines->contains('id', $lineId) ? [(int) $movement->id => $lineId] : [];
        })->all();
        $source = $this->descriptor($document, 'PURCHASE_DOCUMENT', $revision, 'document_number', 'document_date', $lines->pluck('id')->all(), $movementLines);
        if ($revision > 0 && ($date = $movements->max('business_date'))) {
            $source['document_date'] = $date->format('Y-m-d');
        }

        return $source;
    }

    /** @return array<string, mixed> */
    private function purchaseCreditReturn(PurchaseDocument $credit, int $revision): array
    {
        $lines = $credit->lines->filter(fn ($line): bool => (bool) $line->item?->is_stock_item);
        $originalMovementIds = StockMovement::query()->where('source_type', 'PURCHASING')
            ->where('source_id', (string) $credit->original_document_id)->whereNull('metadata->reversal_of_movement_id')
            ->orderBy('id')->limit(max(1, $lines->count()) + 1)->pluck('id');
        $movements = StockMovement::query()->where('source_type', 'PURCHASING')
            ->whereIn('source_id', $originalMovementIds->map(fn ($id): string => (string) $id)->all())
            ->where('metadata->purchase_credit_note_id', $credit->id)->orderBy('id')
            ->limit(max(1, $lines->count()) + 1)->get(['id', 'business_date', 'metadata']);
        $singleLineId = $lines->count() === 1 ? (int) $lines->first()->id : null;
        $movementLines = $movements->mapWithKeys(fn (StockMovement $movement): array => $singleLineId ? [(int) $movement->id => $singleLineId] : [])->all();
        $source = $this->descriptor($credit, 'PURCHASE_CREDIT_RETURN', $revision, 'document_number', 'document_date', $lines->pluck('id')->all(), $movementLines);
        if ($date = $movements->max('business_date')) {
            $source['document_date'] = $date->format('Y-m-d');
        }

        return $source;
    }

    /** @return array<string, mixed> */
    private function purchaseReturn(PurchaseReturn $document, int $revision): array
    {
        return $this->metadataSource($document, 'PURCHASE_RETURN', $revision, 'return_number', 'return_date', 'purchase_return_line_id', 'PURCHASING');
    }

    /** @return array<string, mixed> */
    private function landedCost(LandedCost $document, int $revision): array
    {
        $limit = max(1, (int) config('erp.inventory.revaluation_calculation_node_budget', 1000));
        $allocations = $document->allocations()->with('wmsCostAllocation:id,parent_allocation_id')->orderBy('id')
            ->limit($limit + 1)->get(['id', 'landed_cost_id', 'wms_cost_allocation_id']);
        $overflow = $allocations->count() > $limit;
        $allocations = $allocations->take($limit);
        $mappedRootIds = $allocations->pluck('wmsCostAllocation.parent_allocation_id')->filter();
        $rootIds = $mappedRootIds->map(fn ($id): int => (int) $id)->unique()->sort()->values();
        $source = $this->descriptor($document, 'LANDED_COST', $revision, 'document_number', 'business_date', $rootIds->all(), []);
        $source['allocation_lines'] = $rootIds->mapWithKeys(fn (int $id): array => [$id => $id])->all();
        $source['force_effective_date'] = $document->business_date?->format('Y-m-d');
        if ($mappedRootIds->count() !== $allocations->count()) {
            $source['blockers'][] = 'LANDED_COST_ROOT_MAPPING_INCOMPLETE';
        }
        if ($overflow) {
            $source['blockers'][] = 'TRIGGER_ROOT_LIMIT_REACHED';
        }

        return $source;
    }

    /** @return array<string, mixed> */
    private function salesReturn(SalesReturn $document, int $revision): array
    {
        $lineIds = $document->lines->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $limit = max(1, (int) config('erp.inventory.revaluation_calculation_node_budget', 1000));
        $movementLines = SalesReturnInventoryLink::query()->whereIn('sales_return_line_id', $lineIds)
            ->whereNotNull('reversal_stock_movement_id')->orderBy('id')
            ->limit($limit + 1)
            ->get(['sales_return_line_id', 'reversal_stock_movement_id'])
            ->mapWithKeys(fn (SalesReturnInventoryLink $link): array => [(int) $link->reversal_stock_movement_id => (int) $link->sales_return_line_id])
            ->all();

        return $this->descriptor($document, 'SALES_RETURN', $revision, 'document_number', 'document_date', $lineIds, $movementLines);
    }

    /** @return array<string, mixed> */
    private function metadataSource(Model $document, string $type, int $revision, string $referenceField, string $dateField, string $lineKey, string $sourceType, bool $stockOnly = false): array
    {
        $lines = $stockOnly ? $document->lines->filter(fn ($line): bool => (bool) $line->item?->is_stock_item) : $document->lines;
        $limit = max(1, (int) config('erp.inventory.revaluation_calculation_node_budget', 1000));
        $movements = StockMovement::query()->where('source_type', $sourceType)
            ->where(function ($query) use ($document, $type): void {
                if ($type === 'SALES_RETURN') {
                    $query->where('metadata->sales_return_id', $document->id);
                } else {
                    $query->where('source_id', (string) $document->id);
                }
            })->where('source_reference', (string) $document->{$referenceField})->orderBy('id')->limit($limit + 1)
            ->get(['id', 'source_id', 'source_reference', 'metadata']);
        $singleLineId = $lines->count() === 1 ? (int) $lines->first()->id : null;
        $movementLines = [];
        foreach ($movements as $movement) {
            $lineId = (int) data_get($movement->metadata, $lineKey);
            if ($lineId < 1 && $type === 'PURCHASE_RETURN') {
                $goodsReceiptLineId = (int) data_get($movement->metadata, 'goods_receipt_line_id');
                $lineId = (int) ($lines->firstWhere('goods_receipt_line_id', $goodsReceiptLineId)?->id ?? 0);
            }
            $lineId = $lineId > 0 ? $lineId : $singleLineId;
            if ($lineId) {
                $movementLines[(int) $movement->id] = $lineId;
            }
        }

        return $this->descriptor($document, $type, $revision, $referenceField, $dateField, $lines->pluck('id')->all(), $movementLines);
    }

    /** @return array<string, mixed> */
    private function descriptor(Model $document, string $type, int $revision, string $referenceField, string $dateField, array $lineIds, array $movementLines): array
    {
        $status = strtoupper((string) $document->status);
        $allowed = match ($type) {
            'GOODS_RECEIPT' => ['APPROVED', 'POSTED'],
            'WMS_TRANSFER' => ['DISPATCHED', 'ACCEPTED', 'PARTIALLY_ACCEPTED', 'REJECTED', 'VOID'],
            default => ['POSTED', 'REVERSED'],
        };

        return [
            'document_type' => $type,
            'document_id' => (int) $document->id,
            'document_reference' => (string) ($document->{$referenceField} ?? ''),
            'document_date' => $document->{$dateField}?->format('Y-m-d'),
            'status' => $status,
            'revision' => $revision,
            'branch_id' => isset($document->branch_id)
                ? (int) $document->branch_id
                : ((int) ($document->warehouse?->branch_id ?? 0) ?: null),
            'line_ids' => array_values(array_map('intval', $lineIds)),
            'movement_lines' => array_map('intval', $movementLines),
            'blockers' => in_array($status, $allowed, true) ? [] : ["SOURCE_DOCUMENT_NOT_POSTED:{$status}"],
        ];
    }
}
