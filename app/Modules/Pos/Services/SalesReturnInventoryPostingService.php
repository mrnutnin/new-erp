<?php

namespace App\Modules\Pos\Services;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalEntryLine;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Pos\Models\PhysicalSale;
use App\Modules\Pos\Models\SalesReturn;
use App\Modules\Pos\Models\SalesReturnInventoryLink;
use App\Modules\Pos\Models\SalesReturnLine;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\CostAllocationJournalLine;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Services\InventoryCostAllocationService;
use App\Modules\Wms\Services\StockMovementService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/** Stock/COGS leg only; the caller owns the outer Sales Return transaction. */
final class SalesReturnInventoryPostingService
{
    public function __construct(
        private readonly StockMovementService $movements,
        private readonly InventoryCostAllocationService $allocations,
        private readonly JournalPostingService $journals,
    ) {}

    public function postWithinTransaction(SalesReturn $return, Warehouse $warehouse, string $date, User $actor): JournalEntry
    {
        $return = SalesReturn::query()->with('lines')->lockForUpdate()->findOrFail($return->id);
        $sale = PhysicalSale::query()->with('lines')->lockForUpdate()->findOrFail($return->physical_sale_id);
        if ($return->status !== 'DRAFT' || $sale->status !== 'POSTED' || (int) $return->warehouse_id !== (int) $warehouse->id || (int) $sale->warehouse_id !== (int) $warehouse->id) {
            throw ValidationException::withMessages(['sales_return' => 'Post รับคืนได้เฉพาะร่างที่อ้าง HS/IV Posted ในคลังเดียวกัน']);
        }
        $rows = [];
        foreach ($return->lines as $line) {
            $this->assertReturnQuantity($return, $line, $sale);
            $source = StockMovement::query()->where('warehouse_id', $warehouse->id)->where('source_type', 'POS')->where('source_id', (string) $sale->id)
                ->where('status', 'POSTED')->whereJsonContains('metadata->physical_sale_line_id', $line->physical_sale_line_id)->lockForUpdate()->sole();
            $sources = CostAllocation::query()->where('stock_movement_id', $source->id)->where('status', 'POSTED')->where('cost_status', 'FINAL')->lockForUpdate()->get();
            if ($sources->isEmpty()) {
                throw ValidationException::withMessages(['stock' => "ไม่พบต้นทุน FINAL ของบรรทัด {$line->line_number}"]);
            }
            $sourceValue = $sources->reduce(fn (BigDecimal $sum, CostAllocation $a): BigDecimal => $sum->plus(BigDecimal::of((string) $a->value)->abs()), BigDecimal::zero());
            $sourceQty = BigDecimal::of((string) $source->base_quantity);
            $returnQty = BigDecimal::of((string) $line->stock_quantity);
            $unitCost = $sourceValue->dividedBy($sourceQty, 8, RoundingMode::HALF_UP)->__toString();
            $movement = $this->movements->recordIntent([
                'warehouse_id' => $warehouse->id, 'item_id' => $line->item_id, 'uom_id' => $line->stock_uom_id,
                'movement_type' => $source->movement_type, 'direction' => 'IN', 'quantity' => $line->quantity, 'base_quantity' => $line->stock_quantity,
                'business_date' => $date, 'source_type' => 'POS', 'source_id' => "sales-return:{$return->id}:line:{$line->id}", 'source_reference' => $return->document_number,
                'idempotency_key' => "sales-return:{$return->id}:line:{$line->id}:movement", 'created_by' => $actor->id,
                'metadata' => ['sales_return_id' => $return->id, 'sales_return_line_id' => $line->id, 'physical_sale_line_id' => $line->physical_sale_line_id, 'unit_cost' => $unitCost, 'unit_cost_trusted' => true],
            ]);
            $movement = $this->movements->postWithinTransaction($movement);
            $receipt = $this->allocations->record($movement, (string) ($sources->first()->method ?: 'RETURN'), [
                'allocation_type' => 'RETURN', 'quantity' => $returnQty->__toString(), 'unit_cost' => $unitCost,
                'value' => $sourceValue->multipliedBy($returnQty)->dividedBy($sourceQty, 8, RoundingMode::HALF_UP)->__toString(),
                'idempotency_key' => "sales-return:{$return->id}:line:{$line->id}:allocation",
            ]);
            foreach ($this->partialLineage($receipt, $sources, $returnQty, $sourceQty) as $allocation) {
                $sourceAllocation = $sources->firstWhere('id', $allocation->parent_allocation_id);
                $inventorySourceLineId = CostAllocationJournalLine::query()->where('allocation_id', $sourceAllocation->id)->value('journal_entry_line_id');
                $inventorySourceLine = $inventorySourceLineId ? JournalEntryLine::query()->find($inventorySourceLineId) : null;
                $cogsSourceLine = $inventorySourceLine ? JournalEntryLine::query()->where('journal_entry_id', $inventorySourceLine->journal_entry_id)->where('line_number', $inventorySourceLine->line_number - 1)->first() : null;
                if (! $inventorySourceLine || ! $cogsSourceLine) {
                    throw ValidationException::withMessages(['journal' => "ไม่พบ COGS proof ของบรรทัด {$line->line_number}"]);
                }
                $value = BigDecimal::of((string) $allocation->value)->abs()->toScale(2, RoundingMode::HALF_UP)->__toString();
                $rows[] = compact('line', 'source', 'movement', 'allocation', 'sourceAllocation', 'inventorySourceLine', 'cogsSourceLine', 'value');
            }
        }
        $journal = $this->journals->postWithinTransaction(['source_type' => 'POS', 'source_id' => "sales-return:{$return->id}:cogs", 'source_reference' => $return->document_number,
            'event_code' => 'sales_cogs', 'entry_date' => $date, 'document_date' => $return->document_date->format('Y-m-d'), 'description' => "รับคืน COGS {$return->document_number}",
            'lines' => collect($rows)->flatMap(fn (array $row) => [
                ['account_id' => $row['inventorySourceLine']->account_id, 'subledger_type' => 'ITEM', 'subledger_id' => (string) $row['line']->item_id, 'description' => $return->document_number, 'debit' => $row['value'], 'credit' => '0.00'],
                ['account_id' => $row['cogsSourceLine']->account_id, 'description' => $return->document_number, 'debit' => '0.00', 'credit' => $row['value']],
            ])->all()], $warehouse, $actor);
        foreach ($rows as $index => $row) {
            $inventoryLine = $journal->lines()->where('line_number', ($index * 2) + 1)->sole();
            $this->allocations->linkJournalLineWithinTransaction($row['allocation'], $inventoryLine);
            SalesReturnInventoryLink::query()->firstOrCreate(['sales_return_line_id' => $row['line']->id, 'source_stock_movement_id' => $row['source']->id, 'source_cost_allocation_id' => $row['sourceAllocation']->id], ['reversal_stock_movement_id' => $row['movement']->id, 'reversal_cost_allocation_id' => $row['allocation']->id]);
        }

        return $journal;
    }

    private function assertReturnQuantity(SalesReturn $return, SalesReturnLine $line, PhysicalSale $sale): void
    {
        $source = $sale->lines->firstWhere('id', $line->physical_sale_line_id);
        $used = SalesReturnLine::query()->where('physical_sale_line_id', $line->physical_sale_line_id)->where('sales_return_id', '!=', $return->id)
            ->whereHas('returnDocument', fn ($query) => $query->where('status', 'POSTED'))->lockForUpdate()->sum('quantity');
        if (! $source || BigDecimal::of((string) $line->quantity)->plus(BigDecimal::of((string) $used))->isGreaterThan(BigDecimal::of((string) $source->quantity))) {
            throw ValidationException::withMessages(['lines' => "จำนวนรับคืนบรรทัด {$line->line_number} เกินจำนวนขายคงเหลือ"]);
        }
    }

    /** @return list<CostAllocation> */
    private function partialLineage(CostAllocation $receipt, $sources, BigDecimal $returnQty, BigDecimal $sourceQty): array
    {
        $ratio = $returnQty->dividedBy($sourceQty, 12, RoundingMode::HALF_UP);
        $result = [];
        foreach ($sources->values() as $index => $source) {
            $values = [
                'parent_allocation_id' => $source->id,
                'quantity' => $returnQty->isEqualTo($sourceQty) ? $source->quantity : BigDecimal::of((string) $source->quantity)->multipliedBy($ratio)->toScale(8, RoundingMode::HALF_UP)->__toString(),
                'unit_cost' => $source->unit_cost,
                'value' => BigDecimal::of((string) $source->value)->abs()->multipliedBy($ratio)->toScale(8, RoundingMode::HALF_UP)->__toString(),
                'idempotency_key' => "sales-return:{$receipt->stock_movement_id}:source:{$source->id}",
                'metadata' => [...(is_array($receipt->metadata) ? $receipt->metadata : []), 'reversal_of_allocation_id' => $source->id],
            ];
            if ($index === 0) {
                $receipt->forceFill($values)->save();
                $result[] = $receipt->fresh();
            } else {
                $result[] = CostAllocation::query()->firstOrCreate(['idempotency_key' => $values['idempotency_key']], [...$receipt->only(['stock_movement_id', 'stock_cost_layer_id', 'recost_request_id', 'journal_entry_id', 'warehouse_id', 'item_id', 'uom_id', 'allocation_type', 'direction', 'cost_status', 'status', 'method', 'policy_version', 'revision', 'business_date']), ...$values]);
            }
        }

        return $result;
    }
}
