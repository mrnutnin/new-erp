<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Support\InventoryReconciliationCalculator;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class InventoryReconciliationService
{
    public function historicalQuery(string $asOfDate, int $warehouseId, ?int $itemId = null): Builder
    {
        $allocations = app(InventoryCostAllocationService::class)->historicalValuationQuery($asOfDate, $warehouseId, $itemId)->toBase();
        $gl = DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')
            ->where('entries.warehouse_id', $warehouseId)
            // Keep the original REVERSED entry together with its POSTED
            // reversal so the inventory control balance nets to zero.
            ->whereIn('entries.status', ['POSTED', 'REVERSED'])
            ->where('entries.entry_date', '<=', $asOfDate)
            ->where('accounts.control_account_type', 'INVENTORY')
            ->where('lines.subledger_type', 'ITEM')
            ->select('lines.subledger_id as item_id')
            ->selectRaw('SUM(lines.debit - lines.credit) AS gl_value')
            ->groupBy('lines.subledger_id');

        $balance = DB::table('wms_stock_balances')
            ->where('warehouse_id', $warehouseId)
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->select('item_id')
            ->selectRaw('SUM(inventory_value) AS balance_value')
            ->groupBy('item_id');

        return DB::query()->fromSub($allocations, 'valuation')
            ->join('wms_items', 'wms_items.id', '=', 'valuation.item_id')
            ->leftJoinSub($gl, 'gl', fn ($join) => $join->whereRaw('CAST(gl.item_id AS UNSIGNED) = valuation.item_id'))
            ->leftJoinSub($balance, 'balance', fn ($join) => $join->on('balance.item_id', '=', 'valuation.item_id'))
            ->select([
                'valuation.item_id', 'valuation.final_quantity', 'valuation.final_value',
                'valuation.pending_value', 'valuation.pending_count', 'valuation.unlinked_count',
                'wms_items.code AS item_code', 'wms_items.name AS item_name',
            ])
            ->selectRaw('COALESCE(balance.balance_value, 0) AS balance_value')
            ->selectRaw('COALESCE(gl.gl_value, 0) AS gl_value')
            ->selectRaw('valuation.final_value - COALESCE(gl.gl_value, 0) AS difference')
            ->selectRaw('COALESCE(balance.balance_value, 0) - valuation.final_value AS balance_difference');
    }

    /**
     * Read-only totals. Stock balance is explicitly the current projection;
     * historical balance requires replaying allocations and is not inferred here.
     */
    public function totals(string $asOfDate, int|array $warehouseId, ?int $itemId = null): array
    {
        $warehouseIds = collect(is_array($warehouseId) ? $warehouseId : [$warehouseId])
            ->map(fn ($id): int => (int) $id)->filter()->unique()->values()->all();
        $allocation = CostAllocation::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->where('business_date', '<=', $asOfDate)
            ->where('status', '!=', 'REVERSED')
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->selectRaw('COALESCE(SUM(value), 0) AS value')
            ->selectRaw('COALESCE(SUM(CASE WHEN journal_entry_id IS NULL THEN 1 ELSE 0 END), 0) AS unlinked_count')
            ->first();

        $balance = StockBalance::query()
            ->whereIn('warehouse_id', $warehouseIds)
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->sum('inventory_value');

        $gl = DB::table('journal_entry_lines as lines')
            ->join('journal_entries as entries', 'entries.id', '=', 'lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'lines.account_id')
            ->whereIn('entries.warehouse_id', $warehouseIds)
            // A reversed source and its posted contra must both be included
            // in this control-account reconciliation.
            ->whereIn('entries.status', ['POSTED', 'REVERSED'])
            ->where('entries.entry_date', '<=', $asOfDate)
            ->where('accounts.control_account_type', 'INVENTORY')
            // Reconciliation is item-subledger based. Without this predicate
            // a control-only Inventory line could be counted in totals while
            // historicalQuery correctly excludes it.
            ->where('lines.subledger_type', 'ITEM')
            ->when($itemId, fn ($query) => $query->where('lines.subledger_id', (string) $itemId))
            ->selectRaw('COALESCE(SUM(lines.debit - lines.credit), 0) AS value')
            ->value('value');

        $unresolvedLegacyReview = Schema::hasTable('wms_cost_allocation_reviews')
            ? DB::table('wms_cost_allocation_reviews as reviews')
                ->join('wms_cost_allocations as allocations', 'allocations.id', '=', 'reviews.allocation_id')
                ->whereIn('allocations.warehouse_id', $warehouseIds)
                ->where('reviews.status', 'OPEN')
                ->when($itemId, fn ($query) => $query->where('allocations.item_id', $itemId))
                ->count()
            : 0;

        return [
            'as_of_date' => $asOfDate,
            'warehouse_ids' => $warehouseIds,
            'item_id' => $itemId,
            'balance_basis' => 'CURRENT_PROJECTION',
            'unresolved_legacy_review' => $unresolvedLegacyReview,
            ...InventoryReconciliationCalculator::totals(
                (string) ($allocation->value ?? '0'),
                (string) ($balance ?? '0'),
                (string) ($gl ?? '0'),
                (int) ($allocation->unlinked_count ?? 0),
            ),
        ];
    }
}
