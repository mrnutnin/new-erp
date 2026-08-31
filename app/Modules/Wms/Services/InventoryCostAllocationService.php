<?php

namespace App\Modules\Wms\Services;

use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostAllocationJournalLine;
use App\Modules\Wms\Models\StockCostLayer;
use App\Modules\Wms\Models\StockMovement;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class InventoryCostAllocationService
{
    private const POLICY_VERSION = 'costing-v1';

    public function record(StockMovement $movement, string $method, array $row, ?int $recostRequestId = null): CostAllocation
    {
        $type = $row['allocation_type'] ?? $this->type($movement);
        $key = (string) ($row['idempotency_key'] ?? "movement:{$movement->id}:{$type}:".Str::uuid());
        $quantity = $this->decimal($row['quantity']);
        $unitCost = $this->decimal($row['unit_cost']);
        $value = $this->decimal($row['value']);

        $values = [
            'stock_movement_id' => $movement->id,
            'stock_cost_layer_id' => $row['stock_cost_layer_id'] ?? null,
            'recost_request_id' => $recostRequestId,
            'parent_allocation_id' => $row['parent_allocation_id'] ?? null,
            'warehouse_id' => $movement->warehouse_id,
            'item_id' => $movement->item_id,
            'uom_id' => $movement->uom_id,
            'allocation_type' => $type,
            'direction' => $row['direction'] ?? $movement->direction,
            'cost_status' => $row['cost_status'] ?? 'FINAL',
            'status' => 'PENDING',
            'method' => $method,
            'policy_version' => self::POLICY_VERSION,
            'revision' => $row['revision'] ?? 0,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'value' => $value,
            'business_date' => $movement->business_date,
            'metadata' => $row['metadata'] ?? null,
        ];
        try {
            return DB::transaction(function () use ($key, $values): CostAllocation {
                $existing = CostAllocation::query()->where('idempotency_key', $key)->lockForUpdate()->first();
                if ($existing) {
                    return $this->assertSameIdentity($existing, $values);
                }

                return CostAllocation::query()->create([...$values, 'idempotency_key' => $key]);
            }, 3);
        } catch (QueryException $exception) {
            if (! str_contains(strtolower($exception->getMessage()), 'duplicate') && $exception->getCode() !== '23000') {
                throw $exception;
            }

            $existing = CostAllocation::query()->where('idempotency_key', $key)->first();
            if (! $existing) {
                throw $exception;
            }

            return $this->assertSameIdentity($existing, $values);
        }
    }

    /**
     * Add immutable allocation→Journal-line proof inside the caller's outer
     * transaction. No nested transaction is opened here.
     */
    public function linkJournalLineWithinTransaction(CostAllocation $allocation, JournalEntryLine $line): CostAllocationJournalLine
    {
        $allocation = CostAllocation::query()->lockForUpdate()->findOrFail($allocation->id);
        $line = JournalEntryLine::query()->lockForUpdate()->findOrFail($line->id);
        if ($allocation->journal_entry_id !== null && (int) $allocation->journal_entry_id !== (int) $line->journal_entry_id) {
            throw ValidationException::withMessages(['journal_entry_id' => 'Cost allocation ถูกผูกกับ Journal คนละรายการแล้ว']);
        }

        $identity = hash('sha256', implode('|', [(string) $allocation->id, (string) $line->id, (string) $allocation->revision]));
        $existing = CostAllocationJournalLine::query()->where('identity_key', $identity)->lockForUpdate()->first();
        if ($existing) {
            if ((int) $existing->allocation_id !== (int) $allocation->id || (int) $existing->journal_entry_line_id !== (int) $line->id) {
                throw ValidationException::withMessages(['identity_key' => 'Journal linkage identity ถูกใช้กับข้อมูลอื่นแล้ว']);
            }

            // A legacy allocation may already have the exact immutable link
            // but still be PENDING. Re-running this bounded, idempotent
            // linkage must complete the lifecycle without replacing the link.
            if ($allocation->status === 'PENDING') {
                $allocation->forceFill(['status' => 'POSTED'])->save();
            }

            return $existing;
        }

        // A cost allocation is pending until its immutable GL proof exists.
        // Once linked, it is posted; leaving PENDING here makes the release
        // gate reject an otherwise complete Inventory -> GL chain.
        $allocation->forceFill(['journal_entry_id' => $line->journal_entry_id, 'status' => 'POSTED'])->save();

        return CostAllocationJournalLine::query()->create([
            'allocation_id' => $allocation->id,
            'journal_entry_line_id' => $line->id,
            'revision' => $allocation->revision,
            'identity_key' => $identity,
        ]);
    }

    /**
     * Create the immutable cost delta for a reversal movement. The caller must
     * have already applied the movement through StockMovementService inside the
     * same outer transaction. This method deliberately does not mark or mutate
     * the source allocation and opens no nested transaction.
     */
    public function reverseWithinTransaction(CostAllocation $source, StockMovement $reversal, array $attributes = []): CostAllocation
    {
        $source = CostAllocation::query()->lockForUpdate()->findOrFail($source->id);
        if ($source->status === 'REVERSED') {
            throw ValidationException::withMessages(['allocation' => 'Cost allocation นี้ถูกกลับรายการแล้ว']);
        }
        if ((int) $source->stock_movement_id === (int) $reversal->id) {
            throw ValidationException::withMessages(['movement' => 'Reversal movement ต้องเป็นรายการใหม่']);
        }

        $key = trim((string) ($attributes['idempotency_key'] ?? "reversal:allocation:{$source->id}:revision:".((int) $source->revision + 1)));
        if ($key === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'ต้องมี reversal allocation idempotency key']);
        }
        $values = [
            'stock_movement_id' => $reversal->id,
            'stock_cost_layer_id' => $attributes['stock_cost_layer_id'] ?? null,
            'recost_request_id' => null,
            'parent_allocation_id' => $source->id,
            'journal_entry_id' => null,
            'warehouse_id' => $reversal->warehouse_id,
            'item_id' => $reversal->item_id,
            'uom_id' => $reversal->uom_id,
            'allocation_type' => $attributes['allocation_type'] ?? $source->allocation_type,
            'direction' => $reversal->direction,
            'cost_status' => $source->cost_status,
            // The reversal is complete only after its immutable GL proof is
            // linked by linkJournalLineWithinTransaction().
            'status' => 'PENDING',
            'method' => $source->method,
            'policy_version' => self::POLICY_VERSION,
            'revision' => (int) $source->revision + 1,
            'quantity' => $this->decimal($source->quantity),
            'unit_cost' => $this->decimal($source->unit_cost),
            'value' => $this->decimal(BigDecimal::of((string) $source->value)->negated()),
            'business_date' => $reversal->business_date,
            'metadata' => [
                ...(is_array($source->metadata) ? $source->metadata : []),
                'reversal_of_allocation_id' => $source->id,
                'reversal_of_movement_id' => $source->stock_movement_id,
            ],
        ];
        $existing = CostAllocation::query()->where('idempotency_key', $key)->lockForUpdate()->first();
        if ($existing) {
            return $this->assertSameIdentity($existing, [...$values, 'idempotency_key' => $key]);
        }

        return CostAllocation::query()->create([...$values, 'idempotency_key' => $key]);
    }

    public function recordRecost(StockMovement $receipt, StockCostLayer $pending, string $quantity, string $delta, int $requestId): CostAllocation
    {
        $key = "recost:layer:{$pending->id}:receipt:{$receipt->id}";
        $unit = BigDecimal::of($delta)
            ->dividedBy(BigDecimal::of($quantity), 8, RoundingMode::HALF_UP)
            ->toScale(8, RoundingMode::HALF_UP)
            ->__toString();

        $parentId = DB::transaction(fn (): ?int => CostAllocation::query()
            // A provisional issue has no real FIFO layer yet. Link the delta
            // to that issue allocation through the layer's source movement;
            // a retry must resolve to the same immutable parent.
            ->where(function (Builder $query) use ($pending): void {
                $query->where('stock_cost_layer_id', $pending->id)
                    ->orWhere('stock_movement_id', $pending->source_movement_id);
            })
            ->where('allocation_type', '!=', 'RECOST')
            ->orderBy('id')
            ->lockForUpdate()
            ->value('id'));

        return $this->record($receipt, (string) $pending->method, [
            'allocation_type' => 'RECOST', 'direction' => $delta[0] === '-' ? 'OUT' : 'IN',
            'quantity' => $quantity, 'unit_cost' => $unit, 'value' => $delta,
            'cost_status' => 'FINAL', 'stock_cost_layer_id' => $pending->id,
            'parent_allocation_id' => $parentId,
            'idempotency_key' => $key,
        ], $requestId);
    }

    public function asOf(string $date): Builder
    {
        return CostAllocation::query()->where('business_date', '<=', $date)->where('status', '!=', 'REVERSED');
    }

    public function valuation(string $date, ?int $warehouseId = null, ?int $itemId = null): array
    {
        return $this->valuationQuery($date, $warehouseId, $itemId)->get()->map(fn ($row) => ['item_id' => $row->item_id, 'warehouse_id' => $row->warehouse_id, 'quantity' => $row->quantity, 'value' => $row->value])->all();
    }

    public function valuationQuery(string $date, ?int $warehouseId = null, ?int $itemId = null): Builder
    {
        $query = $this->asOf($date)->selectRaw('item_id, warehouse_id, SUM(CASE WHEN allocation_type = "RECOST" THEN 0 WHEN direction = "IN" THEN quantity ELSE -quantity END) quantity, SUM(value) value')->groupBy('item_id', 'warehouse_id');
        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }
        if ($itemId) {
            $query->where('item_id', $itemId);
        }

        return $query;
    }

    public function valuationPage(string $date, ?int $warehouseId = null, ?int $itemId = null, int $perPage = 50): LengthAwarePaginator
    {
        return $this->valuationQuery($date, $warehouseId, $itemId)->paginate(max(1, min($perPage, 200)));
    }

    /**
     * Historical valuation source. Never joins current stock balances; pending
     * provisional value is returned separately so reports cannot call it final.
     */
    public function historicalValuationQuery(string $date, ?int $warehouseId = null, ?int $itemId = null): Builder
    {
        $query = $this->asOf($date)
            ->selectRaw('item_id, warehouse_id')
            ->selectRaw('SUM(CASE WHEN cost_status = "PENDING" OR allocation_type = "RECOST" THEN 0 WHEN direction = "IN" THEN quantity ELSE -quantity END) AS final_quantity')
            ->selectRaw('SUM(CASE WHEN cost_status = "PENDING" THEN 0 ELSE value END) AS final_value')
            ->selectRaw('SUM(CASE WHEN cost_status = "PENDING" THEN value ELSE 0 END) AS pending_value')
            ->selectRaw('SUM(CASE WHEN cost_status = "PENDING" THEN 1 ELSE 0 END) AS pending_count')
            ->selectRaw('SUM(CASE WHEN journal_entry_id IS NULL THEN 1 ELSE 0 END) AS unlinked_count')
            ->groupBy('item_id', 'warehouse_id');

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }
        if ($itemId) {
            $query->where('item_id', $itemId);
        }

        return $query;
    }

    public function historicalValuationPage(string $date, ?int $warehouseId = null, ?int $itemId = null, int $perPage = 50): LengthAwarePaginator
    {
        return $this->historicalValuationQuery($date, $warehouseId, $itemId)->paginate(max(1, min($perPage, 200)));
    }

    private function type(StockMovement $movement): string
    {
        if ($movement->source_type === 'ISSUE_RETURN') {
            return 'RETURN';
        }

        return $movement->movement_type === 'COUNT' ? 'ADJUSTMENT' : $movement->movement_type;
    }

    private function decimal(mixed $value): string
    {
        return BigDecimal::of((string) $value)->toScale(8, RoundingMode::HALF_UP)->__toString();
    }

    private function assertSameIdentity(CostAllocation $existing, array $values): CostAllocation
    {
        foreach (['stock_movement_id', 'stock_cost_layer_id', 'allocation_type', 'method', 'quantity', 'unit_cost', 'value'] as $field) {
            if ((string) $existing->{$field} !== (string) ($values[$field] ?? null)) {
                throw ValidationException::withMessages(['idempotency_key' => 'Cost allocation identity นี้ถูกใช้กับข้อมูลอื่นแล้ว']);
            }
        }

        return $existing;
    }
}
