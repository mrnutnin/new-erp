<?php

namespace App\Modules\Wms\Services;

use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesReturn;
use App\Modules\Purchasing\Models\GoodsReceipt;
use App\Modules\Purchasing\Models\PurchaseDocument;
use App\Modules\Purchasing\Models\PurchaseReturn;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\InventoryAdjustment;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Wms\Models\OpeningBalanceBatch;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Models\TransferEvent;
use Carbon\CarbonImmutable;
use Throwable;

final class EffectiveDocumentDateResolver
{
    /** @var array<string, array{0:?string,1:?string}> */
    private array $authorityCache = [];

    /** Prime document-date authority once per timeline page to avoid N+1 lookups. */
    public function prime(iterable $allocations): void
    {
        $movements = collect($allocations)->map(fn (CostAllocation $allocation) => $allocation->movement)->filter();
        $this->primeIds($movements, 'OPENING_BALANCE', OpeningBalanceBatch::class, 'cutover_date', 'OPENING_BALANCE');
        $this->primeIds($movements, 'ISSUE_DOCUMENT', IssueDocument::class, 'document_date', 'ISSUE_DOCUMENT', true);
        $this->primeIds($movements, 'ISSUE_RETURN', IssueReturn::class, 'document_date', 'ISSUE_RETURN', true);
        $this->primeIds($movements, 'WMS_PRODUCTION_RECEIPT', InventoryAdjustmentDocument::class, 'document_date', 'PRODUCTION_FINISHED_RECEIPT');
        $this->primeIds($movements, 'GOODS_RECEIPT', GoodsReceipt::class, 'business_date', 'GOODS_RECEIPT');
        $this->primeInventoryAdjustments($movements);
        $this->primeTransfers($movements);
        $this->primeReferences($movements, 'POS', PhysicalSale::class, 'document_number', 'document_date', 'PHYSICAL_SALE', SalesReturn::class, 'document_number', 'document_date', 'SALES_RETURN');
        $this->primeReferences($movements, 'PURCHASING', PurchaseDocument::class, 'document_number', 'document_date', 'PURCHASE_DOCUMENT', PurchaseReturn::class, 'return_number', 'return_date', 'PURCHASE_RETURN');
    }

    /**
     * @return array{ready:bool,effective_date:?string,authority_source:?string,movement_date:?string,allocation_date:?string,blockers:list<string>}
     */
    public function resolve(CostAllocation $allocation): array
    {
        $movement = $allocation->relationLoaded('movement')
            ? $allocation->movement
            : $allocation->movement()->first();

        if (! $movement) {
            return $this->assess(null, null, $this->date($allocation->business_date), null, ['MOVEMENT_MISSING']);
        }

        [$authorityDate, $authoritySource] = $this->authority($movement);

        return $this->assess(
            $authorityDate,
            $this->date($movement->business_date),
            $this->date($allocation->business_date),
            $authoritySource,
        );
    }

    /**
     * Pure date gate used by the resolver and unit tests.
     *
     * @param  list<string>  $initialBlockers
     * @return array{ready:bool,effective_date:?string,authority_source:?string,movement_date:?string,allocation_date:?string,blockers:list<string>}
     */
    public function assess(
        ?string $documentDate,
        ?string $movementDate,
        ?string $allocationDate,
        ?string $authoritySource,
        array $initialBlockers = [],
    ): array {
        $documentDate = $this->date($documentDate);
        $movementDate = $this->date($movementDate);
        $allocationDate = $this->date($allocationDate);
        $blockers = $initialBlockers;

        if ($documentDate === null || $authoritySource === null) {
            $blockers[] = 'DATE_AUTHORITY_UNRESOLVED';
        } elseif ($movementDate !== $documentDate || $allocationDate !== $documentDate) {
            $blockers[] = 'DATE_MISMATCH';
        }

        $blockers = array_values(array_unique($blockers));

        return [
            'ready' => $blockers === [],
            'effective_date' => $documentDate,
            'authority_source' => $authoritySource,
            'movement_date' => $movementDate,
            'allocation_date' => $allocationDate,
            'blockers' => $blockers,
        ];
    }

    /** @return array{0:?string,1:?string} */
    private function authority(StockMovement $movement): array
    {
        $metadata = is_array($movement->metadata) ? $movement->metadata : [];
        if (! empty($metadata['reversal_of_movement_id'])) {
            return [$this->date($movement->business_date), 'REVERSAL_EVENT'];
        }

        $key = $this->authorityCacheKey($movement);

        return $this->authorityCache[$key] ??= match ((string) $movement->source_type) {
            'OPENING_BALANCE' => $this->openingBalance($movement),
            'INVENTORY' => $this->inventoryAdjustment($movement),
            'ISSUE_DOCUMENT' => $this->modelDate(IssueDocument::withTrashed()->find($this->numericId($movement->source_id)), 'document_date', 'ISSUE_DOCUMENT'),
            'ISSUE_RETURN' => $this->modelDate(IssueReturn::withTrashed()->find($this->numericId($movement->source_id)), 'document_date', 'ISSUE_RETURN'),
            'WMS_TRANSFER' => $this->modelDate(TransferEvent::query()->where('stock_movement_id', $movement->id)->first(), 'business_date', 'TRANSFER_EVENT'),
            'WMS_PRODUCTION_RECEIPT' => $this->modelDate(InventoryAdjustmentDocument::query()->find($this->numericId($movement->source_id)), 'document_date', 'PRODUCTION_FINISHED_RECEIPT'),
            'GOODS_RECEIPT' => $this->modelDate(GoodsReceipt::query()->find($this->numericId($movement->source_id)), 'business_date', 'GOODS_RECEIPT'),
            'PURCHASING' => $this->purchasing($movement),
            'POS' => $this->pos($movement),
            default => [null, null],
        };
    }

    private function authorityCacheKey(StockMovement $movement): string
    {
        return match ((string) $movement->source_type) {
            'WMS_TRANSFER' => 'WMS_TRANSFER|'.$movement->id,
            'PURCHASING', 'POS' => $movement->source_type.'|'.$movement->source_reference,
            default => $movement->source_type.'|'.$movement->source_id,
        };
    }

    private function primeIds($movements, string $type, string $model, string $field, string $source, bool $withTrashed = false): void
    {
        $targets = $movements->where('source_type', $type)->reject(fn (StockMovement $movement): bool => isset($this->authorityCache[$this->authorityCacheKey($movement)]));
        $ids = $targets->map(fn (StockMovement $movement): ?int => $this->numericId($movement->source_id))->filter()->unique()->values();
        if ($targets->isEmpty()) {
            return;
        }
        $query = $withTrashed ? $model::withTrashed() : $model::query();
        $documents = $ids->isEmpty() ? collect() : $query->whereIn('id', $ids)->get(['id', $field])->keyBy('id');
        foreach ($targets as $movement) {
            $this->authorityCache[$this->authorityCacheKey($movement)] = $this->modelDate($documents->get($this->numericId($movement->source_id)), $field, $source);
        }
    }

    private function primeInventoryAdjustments($movements): void
    {
        $targets = $movements->where('source_type', 'INVENTORY')->reject(fn (StockMovement $movement): bool => isset($this->authorityCache[$this->authorityCacheKey($movement)]));
        $lineIds = $targets->map(function (StockMovement $movement): ?int {
            return preg_match('/^adjustment:(\d+)$/', (string) $movement->source_id, $matches) ? (int) $matches[1] : null;
        })->filter()->unique()->values();
        $lines = $lineIds->isEmpty() ? collect() : InventoryAdjustment::query()->with('document:id,document_date')->whereIn('id', $lineIds)->get(['id', 'document_id'])->keyBy('id');
        foreach ($targets as $movement) {
            preg_match('/^adjustment:(\d+)$/', (string) $movement->source_id, $matches);
            $this->authorityCache[$this->authorityCacheKey($movement)] = $this->modelDate($lines->get((int) ($matches[1] ?? 0))?->document, 'document_date', 'INVENTORY_ADJUSTMENT_DOCUMENT');
        }
    }

    private function primeTransfers($movements): void
    {
        $targets = $movements->where('source_type', 'WMS_TRANSFER')->reject(fn (StockMovement $movement): bool => isset($this->authorityCache[$this->authorityCacheKey($movement)]));
        $ids = $targets->pluck('id')->map(fn ($id): int => (int) $id)->unique()->values();
        $events = $ids->isEmpty() ? collect() : TransferEvent::query()->whereIn('stock_movement_id', $ids)->get(['stock_movement_id', 'business_date'])->keyBy('stock_movement_id');
        foreach ($targets as $movement) {
            $this->authorityCache[$this->authorityCacheKey($movement)] = $this->modelDate($events->get($movement->id), 'business_date', 'TRANSFER_EVENT');
        }
    }

    private function primeReferences($movements, string $type, string $primaryModel, string $primaryKey, string $primaryDate, string $primarySource, string $fallbackModel, string $fallbackKey, string $fallbackDate, string $fallbackSource): void
    {
        $targets = $movements->where('source_type', $type)->reject(fn (StockMovement $movement): bool => isset($this->authorityCache[$this->authorityCacheKey($movement)]));
        $references = $targets->pluck('source_reference')->map(fn ($value): string => trim((string) $value))->filter()->unique()->values();
        if ($targets->isEmpty()) {
            return;
        }
        $primary = $references->isEmpty() ? collect() : $primaryModel::query()->whereIn($primaryKey, $references)->get([$primaryKey, $primaryDate])->keyBy($primaryKey);
        $missing = $references->reject(fn (string $reference): bool => $primary->has($reference))->values();
        $fallback = $missing->isEmpty() ? collect() : $fallbackModel::query()->whereIn($fallbackKey, $missing)->get([$fallbackKey, $fallbackDate])->keyBy($fallbackKey);
        foreach ($targets as $movement) {
            $reference = trim((string) $movement->source_reference);
            $this->authorityCache[$this->authorityCacheKey($movement)] = $primary->has($reference)
                ? $this->modelDate($primary->get($reference), $primaryDate, $primarySource)
                : $this->modelDate($fallback->get($reference), $fallbackDate, $fallbackSource);
        }
    }

    /** @return array{0:?string,1:?string} */
    private function openingBalance(StockMovement $movement): array
    {
        return $this->modelDate(
            OpeningBalanceBatch::query()->find($this->numericId($movement->source_id)),
            'cutover_date',
            'OPENING_BALANCE',
        );
    }

    /** @return array{0:?string,1:?string} */
    private function inventoryAdjustment(StockMovement $movement): array
    {
        if (! preg_match('/^adjustment:(\d+)$/', (string) $movement->source_id, $matches)) {
            return [null, null];
        }

        $line = InventoryAdjustment::query()->with('document:id,document_date')->find((int) $matches[1]);

        return $this->modelDate($line?->document, 'document_date', 'INVENTORY_ADJUSTMENT_DOCUMENT');
    }

    /** @return array{0:?string,1:?string} */
    private function purchasing(StockMovement $movement): array
    {
        $reference = trim((string) $movement->source_reference);
        if ($reference === '') {
            return [null, null];
        }

        $document = PurchaseDocument::query()->where('document_number', $reference)->first();
        if ($document) {
            return $this->modelDate($document, 'document_date', 'PURCHASE_DOCUMENT');
        }

        $return = PurchaseReturn::query()->where('return_number', $reference)->first();

        return $this->modelDate($return, 'return_date', 'PURCHASE_RETURN');
    }

    /** @return array{0:?string,1:?string} */
    private function pos(StockMovement $movement): array
    {
        $reference = trim((string) $movement->source_reference);
        if ($reference === '') {
            return [null, null];
        }

        $sale = PhysicalSale::query()->where('document_number', $reference)->first();
        if ($sale) {
            return $this->modelDate($sale, 'document_date', 'PHYSICAL_SALE');
        }

        $return = SalesReturn::query()->where('document_number', $reference)->first();

        return $this->modelDate($return, 'document_date', 'SALES_RETURN');
    }

    /** @return array{0:?string,1:?string} */
    private function modelDate(?object $model, string $field, string $source): array
    {
        $date = $model ? $this->date($model->{$field} ?? null) : null;

        return $date === null ? [null, null] : [$date, $source];
    }

    private function numericId(mixed $value): ?int
    {
        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
    }

    private function date(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
