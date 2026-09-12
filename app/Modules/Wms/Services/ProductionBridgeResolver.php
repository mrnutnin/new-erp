<?php

namespace App\Modules\Wms\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Resolves Material Issue cost into Finished Receipt output partitions without writing. */
final class ProductionBridgeResolver
{
    /** @param list<array<string, mixed>> $parents @return array<string, mixed> */
    public function resolve(array $parents, int $limit = 1000): array
    {
        $limit = max(1, min($limit, 1000));
        $parents = collect($parents)
            ->filter(fn (array $row): bool => ($row['impact_bucket'] ?? null) === 'WIP_CONSUMED' && ($row['estimated_delta_value'] ?? '0.00000000') !== '0.00000000')
            ->keyBy('allocation_id');
        if ($parents->isEmpty() || ! $this->schemaReady()) {
            return $this->result([], [], []);
        }

        $sources = DB::table('wms_cost_allocations as allocation')
            ->join('wms_issue_lines as line', 'line.stock_movement_id', '=', 'allocation.stock_movement_id')
            ->join('wms_issue_documents as document', 'document.id', '=', 'line.document_id')
            ->whereIn('allocation.id', $parents->keys()->all())
            ->whereNull('line.deleted_at')
            ->where('document.issue_type', 'PRODUCTION')->where('document.status', 'POSTED')
            ->get([
                'allocation.id as source_allocation_id', 'allocation.quantity as source_allocation_quantity',
                'allocation.value as source_allocation_value', 'line.id as issue_line_id',
                'document.id as issue_document_id',
            ])
            ->keyBy('source_allocation_id');
        if ($sources->isEmpty()) {
            return $this->result([], [], []);
        }

        if (Schema::hasTable('wms_production_receipt_sources')) {
            $sourceLinks = DB::table('wms_production_receipt_sources')
                ->whereIn('source_allocation_id', $sources->keys()->all())
                ->orderBy('receipt_document_id')->orderBy('position')->orderBy('id')
                ->limit($limit + 1)
                ->get(['receipt_document_id', 'issue_document_id', 'issue_line_id', 'source_allocation_id', 'consumed_quantity', 'consumed_value']);
        } else {
            $sourceLinks = DB::table('wms_inventory_adjustment_documents')
                ->whereIn('source_issue_id', $sources->pluck('issue_document_id')->unique()->all())
                ->where('document_context', 'PRODUCTION_RECEIPT')->where('status', 'POSTED')
                ->orderBy('document_date')->orderBy('id')
                ->limit($limit + 1)
                ->get(['id as receipt_document_id', 'source_issue_id as issue_document_id'])
                ->flatMap(function ($document) use ($sources) {
                    return $sources->where('issue_document_id', $document->issue_document_id)->map(fn ($source): object => (object) [
                        'receipt_document_id' => $document->receipt_document_id,
                        'issue_document_id' => $source->issue_document_id,
                        'issue_line_id' => $source->issue_line_id,
                        'source_allocation_id' => $source->source_allocation_id,
                        'consumed_quantity' => BigDecimal::of((string) $source->source_allocation_quantity)->abs()->__toString(),
                        'consumed_value' => BigDecimal::of((string) $source->source_allocation_value)->abs()->__toString(),
                    ]);
                });
        }
        $limited = $sourceLinks->count() > $limit;
        $sourceLinks = $sourceLinks->take($limit);
        $receiptIds = $sourceLinks->pluck('receipt_document_id')->unique()->values();
        if ($receiptIds->isEmpty()) {
            return $this->result([], [], [], $limited);
        }

        $receiptSourceTotals = Schema::hasTable('wms_production_receipt_sources')
            ? DB::table('wms_production_receipt_sources')->whereIn('receipt_document_id', $receiptIds->all())
                ->selectRaw('receipt_document_id, SUM(ABS(consumed_value)) as source_total_value')
                ->groupBy('receipt_document_id')->pluck('source_total_value', 'receipt_document_id')
            : $receiptIds->mapWithKeys(fn ($id): array => [$id => $sourceLinks->where('receipt_document_id', $id)
                ->reduce(fn (BigDecimal $sum, $row): BigDecimal => $sum->plus(BigDecimal::of((string) $row->consumed_value)->abs()), BigDecimal::zero())->__toString()]);

        $outputs = DB::table('wms_inventory_adjustment_documents as document')
            ->join('wms_inventory_adjustments as line', 'line.document_id', '=', 'document.id')
            ->join('wms_cost_allocations as allocation', 'allocation.id', '=', 'line.cost_allocation_id')
            ->join('wms_stock_movements as movement', 'movement.id', '=', 'allocation.stock_movement_id')
            ->whereIn('document.id', $receiptIds->all())
            ->where('document.document_context', 'PRODUCTION_RECEIPT')->where('document.status', 'POSTED')
            ->where('allocation.status', '!=', 'REVERSED')
            ->orderBy('document.document_date')->orderBy('document.id')->orderBy('line.line_number')->orderBy('allocation.id')
            ->limit($limit + 1)
            ->get([
                'document.id as receipt_document_id', 'document.document_date as business_date',
                'allocation.id as output_allocation_id', 'allocation.warehouse_id', 'allocation.item_id', 'allocation.uom_id',
                'allocation.method', 'allocation.quantity', 'allocation.value', 'movement.source_reference',
            ]);
        $limited = $limited || $outputs->count() > $limit;
        $outputs = $outputs->take($limit);
        $outputTotals = $outputs->groupBy('receipt_document_id')->map(fn ($rows): string => $rows
            ->reduce(fn (BigDecimal $sum, $row): BigDecimal => $sum->plus(BigDecimal::of((string) $row->value)->abs()), BigDecimal::zero())->__toString());

        $links = [];
        foreach ($sourceLinks as $sourceLink) {
            $source = $sources->get((int) $sourceLink->source_allocation_id);
            if (! $source) {
                continue;
            }
            $parent = $parents->get((int) $source->source_allocation_id);
            foreach ($outputs->where('receipt_document_id', $sourceLink->receipt_document_id) as $output) {
                $links[] = [
                    'source_allocation_id' => (int) $source->source_allocation_id,
                    'issue_document_id' => (int) $source->issue_document_id,
                    'source_allocation_quantity' => (string) $source->source_allocation_quantity,
                    'source_allocation_value' => (string) $source->source_allocation_value,
                    'consumed_quantity' => (string) $sourceLink->consumed_quantity,
                    'consumed_value' => (string) $sourceLink->consumed_value,
                    'receipt_source_total_value' => (string) ($receiptSourceTotals->get($sourceLink->receipt_document_id) ?? '0'),
                    'receipt_output_total_value' => (string) ($outputTotals->get($sourceLink->receipt_document_id) ?? '0'),
                    'receipt_document_id' => (int) $output->receipt_document_id,
                    'output_allocation_id' => (int) $output->output_allocation_id,
                    'output_value' => (string) $output->value,
                    'business_date' => (string) $output->business_date,
                    'warehouse_id' => (int) $output->warehouse_id,
                    'item_id' => (int) $output->item_id,
                    'uom_id' => (int) $output->uom_id,
                    'method' => (string) $output->method,
                    'quantity' => (string) $output->quantity,
                    'source_reference' => (string) $output->source_reference,
                    'parent_business_date' => $parent['business_date'] ?? null,
                ];
            }
        }

        return $this->compile($parents->values()->all(), $links, $limited);
    }

    /** @param list<array<string,mixed>> $parents @param list<array<string,mixed>> $links */
    public function compile(array $parents, array $links, bool $limited = false): array
    {
        $parents = collect($parents)->keyBy('allocation_id');
        $edges = [];
        $blockers = $limited ? ['PRODUCTION_BRIDGE_FAN_OUT_LIMIT_EXCEEDED'] : [];

        foreach (collect($links)->groupBy('source_allocation_id') as $parentId => $rows) {
            $parent = $parents->get((int) $parentId);
            $parentBlocked = false;
            if (! $parent) {
                $blockers[] = "ALLOCATION_{$parentId}:PRODUCTION_SOURCE_ALLOCATION_MISSING";

                continue;
            }
            $rows = $rows->unique(fn (array $row): string => ($row['receipt_document_id'] ?? 0).':'.($row['output_allocation_id'] ?? 0))
                ->sortBy(fn (array $row): string => implode('|', [$row['business_date'] ?? '', str_pad((string) ($row['receipt_document_id'] ?? 0), 20, '0', STR_PAD_LEFT), str_pad((string) ($row['output_allocation_id'] ?? 0), 20, '0', STR_PAD_LEFT)]))->values();
            $sourceQuantity = BigDecimal::of((string) ($rows->first()['source_allocation_quantity'] ?? '0'))->abs();
            $sourceValue = BigDecimal::of((string) ($rows->first()['source_allocation_value'] ?? '0'))->abs();
            if ($sourceQuantity->isLessThanOrEqualTo(0) || $sourceValue->isLessThanOrEqualTo(0)) {
                $blockers[] = "ALLOCATION_{$parentId}:PRODUCTION_SOURCE_VALUE_INVALID";

                continue;
            }

            $sourceSplits = $rows->unique('receipt_document_id');
            $consumedQuantity = $sourceSplits->reduce(fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus(BigDecimal::of((string) ($row['consumed_quantity'] ?? '0'))->abs()), BigDecimal::zero());
            $consumedValue = $sourceSplits->reduce(fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus(BigDecimal::of((string) ($row['consumed_value'] ?? '0'))->abs()), BigDecimal::zero());
            if ($consumedQuantity->isGreaterThan($sourceQuantity) || $consumedValue->isGreaterThan($sourceValue)) {
                $blockers[] = "ALLOCATION_{$parentId}:PRODUCTION_SOURCE_CONSUMPTION_EXCEEDED";

                continue;
            }
            if (! $consumedQuantity->dividedBy($sourceQuantity, 8, RoundingMode::HALF_UP)
                ->isEqualTo($consumedValue->dividedBy($sourceValue, 8, RoundingMode::HALF_UP))) {
                $blockers[] = "ALLOCATION_{$parentId}:PRODUCTION_SOURCE_SPLIT_MISMATCH";

                continue;
            }

            foreach ($sourceSplits as $sourceSplit) {
                $receiptSourceTotal = BigDecimal::of((string) ($sourceSplit['receipt_source_total_value'] ?? '0'))->abs();
                $receiptOutputTotal = BigDecimal::of((string) ($sourceSplit['receipt_output_total_value'] ?? '0'))->abs();
                if ($receiptSourceTotal->isLessThanOrEqualTo(0) || ! $receiptSourceTotal->toScale(8, RoundingMode::HALF_UP)->isEqualTo($receiptOutputTotal->toScale(8, RoundingMode::HALF_UP))) {
                    $blockers[] = 'RECEIPT_'.((int) ($sourceSplit['receipt_document_id'] ?? 0)).':PRODUCTION_RECEIPT_VALUE_MISMATCH';
                    $parentBlocked = true;
                }
            }
            if ($parentBlocked) {
                continue;
            }

            $parentDelta = BigDecimal::of((string) $parent['estimated_delta_value'])->negated();
            $distributed = BigDecimal::zero();
            foreach ($rows as $index => $row) {
                $issues = [];
                if (($row['business_date'] ?? null) < ($parent['business_date'] ?? null)) {
                    $issues[] = 'PRODUCTION_OUTPUT_BEFORE_MATERIAL_ISSUE';
                }
                $isFinal = $index === $rows->count() - 1
                    && $consumedQuantity->isEqualTo($sourceQuantity)
                    && $consumedValue->isEqualTo($sourceValue);
                $delta = $isFinal
                    ? $parentDelta->minus($distributed)
                    : $parentDelta
                        ->multipliedBy(BigDecimal::of((string) $row['consumed_value'])->abs())
                        ->dividedBy($sourceValue, 16, RoundingMode::HALF_UP)
                        ->multipliedBy(BigDecimal::of((string) $row['output_value'])->abs())
                        ->dividedBy(BigDecimal::of((string) $row['receipt_source_total_value'])->abs(), 8, RoundingMode::HALF_UP);
                $distributed = $distributed->plus($delta);
                $outputId = (int) ($row['output_allocation_id'] ?? 0);
                foreach ($issues as $issue) {
                    $blockers[] = "ALLOCATION_{$outputId}:{$issue}";
                }
                $edges[] = [
                    'parent_allocation_id' => (int) $parentId,
                    'child_allocation_id' => $outputId,
                    'relation' => 'PRODUCTION_OUTPUT',
                    'issue_document_id' => (int) ($row['issue_document_id'] ?? 0),
                    'receipt_document_id' => (int) ($row['receipt_document_id'] ?? 0),
                    'business_date' => $row['business_date'] ?? null,
                    'source_reference' => $row['source_reference'] ?? null,
                    'quantity' => $this->decimal(BigDecimal::of((string) ($row['quantity'] ?? '0'))),
                    'estimated_delta_value' => $this->decimal($delta),
                    'source_partition_key' => $this->partition($parent),
                    'target_partition_key' => $this->partition($row),
                    'cross_partition' => $this->partition($parent) !== $this->partition($row),
                    'issues' => $issues,
                ];
            }
        }

        $partitions = collect($edges)->where('estimated_delta_value', '!=', '0.00000000')->groupBy('target_partition_key')->map(fn ($rows, string $key): array => [
            'partition_key' => $key,
            'root_allocation_ids' => $rows->pluck('child_allocation_id')->unique()->sort()->values()->all(),
            'estimated_delta_value' => $this->decimal($rows->reduce(fn (BigDecimal $sum, array $row): BigDecimal => $sum->plus($row['estimated_delta_value']), BigDecimal::zero())),
        ])->sortKeys()->values()->all();

        return $this->result($edges, $partitions, array_values(array_unique($blockers)), $limited);
    }

    private function schemaReady(): bool
    {
        return Schema::hasTable('wms_issue_lines') && Schema::hasTable('wms_issue_documents')
            && Schema::hasTable('wms_inventory_adjustment_documents') && Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id');
    }

    /** @param array<string,mixed> $row */
    private function partition(array $row): string
    {
        return implode(':', [(int) ($row['warehouse_id'] ?? 0), (int) ($row['item_id'] ?? 0), (int) ($row['uom_id'] ?? 0), strtoupper((string) ($row['method'] ?? ''))]);
    }

    private function decimal(BigDecimal $value): string
    {
        return $value->toScale(8, RoundingMode::HALF_UP)->__toString();
    }

    private function result(array $edges, array $partitions, array $blockers, bool $limited = false): array
    {
        return ['read_only' => true, 'ready' => $blockers === [], 'limited' => $limited, 'edges' => $edges, 'partitions' => $partitions, 'blockers' => $blockers];
    }
}
