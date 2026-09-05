<?php

namespace App\Modules\Wms\Services;

use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Support\InventoryGlScope;
use App\Modules\Wms\Support\InventoryPostingPreflight;
use App\Modules\Wms\Support\InventoryReconciliationGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class InventoryPostingPreflightService implements InventoryPostingPreflightReader
{
    public function summary(int $warehouseId): array
    {
        $postedMovements = DB::table('wms_stock_movements')->where('warehouse_id', $warehouseId)->where('status', 'POSTED');
        // WMS release preflight owns the local Inventory -> GL MVP sources.
        // POS has its own sales_cogs posting/reconciliation contract; mixing
        // its allocations here creates false WMS blockers.
        if (Schema::hasColumn('wms_stock_movements', 'source_type')) {
            $postedMovements->whereIn('source_type', InventoryGlScope::LOCAL_MVP_SOURCES);
        }
        $movementIds = (clone $postedMovements)->select('id');
        $allocations = DB::table('wms_cost_allocations')->whereIn('stock_movement_id', $movementIds)->where('status', '!=', 'REVERSED');
        $lineProofAvailable = Schema::hasTable('wms_cost_allocation_journal_lines');
        $missingInventory = DB::table('wms_stock_movements as movements')
            ->join('wms_items as items', 'items.id', '=', 'movements.item_id')
            ->leftJoin('accounts', 'accounts.id', '=', 'items.inventory_account_id')
            ->where('movements.warehouse_id', $warehouseId)->where('movements.status', 'POSTED')
            ->where(fn ($query) => $query->whereNull('accounts.id')->orWhere('accounts.is_active', false)->orWhere('accounts.is_postable', false)->orWhereNull('accounts.control_account_type')->orWhere('accounts.control_account_type', '!=', 'INVENTORY'))
            ->distinct('movements.item_id')->count('movements.item_id');
        $missingCogs = DB::table('wms_stock_movements as movements')
            ->join('wms_items as items', 'items.id', '=', 'movements.item_id')
            ->leftJoin('accounts', 'accounts.id', '=', 'items.cogs_account_id')
            ->leftJoin('account_types', 'account_types.id', '=', 'accounts.account_type_id')
            ->where('movements.warehouse_id', $warehouseId)->where('movements.status', 'POSTED')->where('movements.direction', 'OUT')
            ->where(fn ($query) => $query->whereNull('accounts.id')->orWhere('accounts.is_active', false)->orWhere('accounts.is_postable', false)->orWhereNotNull('accounts.control_account_type')->orWhereNull('account_types.code')->orWhere('account_types.code', '!=', 'EXPENSE'))
            ->distinct('movements.item_id')->count('movements.item_id');
        $missingSource = (clone $postedMovements)
            ->where(fn ($query) => $query
                ->whereNull('source_type')->orWhere('source_type', '')
                ->orWhereNull('source_id')->orWhere('source_id', '')
                ->orWhereNull('source_reference')->orWhere('source_reference', ''))
            ->count();
        $pending = (clone $allocations)->where('cost_status', 'PENDING')->count();
        $unlinked = (clone $allocations)->whereNull('journal_entry_id')->count();
        // Older local schemas keep the source identity in allocation_type/metadata
        // rather than a source_type column. Keep preflight readable across both
        // shapes; never infer a Journal or mutate an allocation here.
        if (Schema::hasColumn('wms_cost_allocations', 'source_type')) {
            $deferredUnlinked = (clone $allocations)->whereNull('journal_entry_id')
                ->whereIn('source_type', InventoryGlScope::DEFERRED_SOURCES)->count();
            $deferredUnlinkedBySource = (clone $allocations)->whereNull('journal_entry_id')
                ->whereIn('source_type', InventoryGlScope::DEFERRED_SOURCES)
                ->select('source_type')->selectRaw('COUNT(*) AS allocation_count')
                ->groupBy('source_type')->pluck('allocation_count', 'source_type')->all();
        } else {
            $deferredUnlinked = (clone $allocations)->whereNull('journal_entry_id')
                ->whereIn('allocation_type', ['ISSUE', 'RETURN', 'TRANSFER'])->count();
            $deferredUnlinkedBySource = [
                'ISSUE_DOCUMENT' => (clone $allocations)->whereNull('journal_entry_id')->where('allocation_type', 'ISSUE')->count(),
                'ISSUE_RETURN' => (clone $allocations)->whereNull('journal_entry_id')->where('allocation_type', 'RETURN')->count(),
                'WMS_TRANSFER' => (clone $allocations)->whereNull('journal_entry_id')->where('allocation_type', 'TRANSFER')->count(),
            ];
            $deferredUnlinkedBySource = array_filter($deferredUnlinkedBySource, static fn (int $count): bool => $count > 0);
        }
        $lineUnlinked = $lineProofAvailable ? DB::table('wms_cost_allocations as allocations')->leftJoin('wms_cost_allocation_journal_lines as links', 'links.allocation_id', '=', 'allocations.id')->whereIn('allocations.stock_movement_id', $movementIds)->where('allocations.status', '!=', 'REVERSED')->whereNull('links.id')->count('allocations.id') : (int) $allocations->count();
        $lineMismatched = $lineProofAvailable ? $this->lineMismatchedCount($movementIds) : 0;
        $lineProofMissing = $lineProofAvailable ? 0 : 1;
        $unresolvedLegacyReview = Schema::hasTable('wms_cost_allocation_reviews')
            ? (int) DB::table('wms_cost_allocation_reviews as reviews')
                ->join('wms_cost_allocations as allocations', 'allocations.id', '=', 'reviews.allocation_id')
                ->where('allocations.warehouse_id', $warehouseId)->where('reviews.status', 'OPEN')->count()
            : 0;
        $globalUnresolvedLegacyReview = Schema::hasTable('wms_cost_allocation_reviews')
            ? (int) DB::table('wms_cost_allocation_reviews')->where('status', 'OPEN')->count()
            : 0;
        $reconciliation = app(InventoryReconciliationService::class)->totals(now()->toDateString(), $warehouseId);
        $reconciliationGate = InventoryReconciliationGate::evaluate($reconciliation);
        $blockers = compact('pending', 'unlinked', 'missingInventory', 'missingCogs', 'missingSource', 'lineUnlinked', 'lineMismatched', 'lineProofMissing', 'unresolvedLegacyReview');

        return [
            'warehouse_id' => $warehouseId,
            'posting_enabled' => (bool) config('erp.inventory.purchase_posting_enabled', false),
            'posted_movements' => (clone $postedMovements)->count(),
            'allocations' => (clone $allocations)->count(),
            'line_proof_available' => $lineProofAvailable,
            'line_unlinked' => $lineUnlinked,
            'line_mismatched' => $lineMismatched,
            'line_proof_missing' => $lineProofMissing,
            'unresolved_legacy_review' => $unresolvedLegacyReview,
            'deferred_unlinked' => $deferredUnlinked,
            'deferred_unlinked_by_source' => $deferredUnlinkedBySource,
            'global_unresolved_legacy_review' => $globalUnresolvedLegacyReview,
            'reconciliation' => $reconciliation,
            'reconciliation_ready' => $reconciliationGate['ready'],
            'reconciliation_blockers' => $reconciliationGate['blockers'],
            'global_ready' => $globalUnresolvedLegacyReview === 0 && $reconciliationGate['ready'],
            ...$blockers,
            'ready' => ! in_array(true, array_map(fn (int $value): bool => $value > 0, $blockers), true),
        ];
    }

    public function inspect(StockMovement $movement): array
    {
        $item = Item::query()->with(['inventoryAccount.type', 'cogsAccount.type'])->find($movement->item_id);
        $lineProofAvailable = Schema::hasTable('wms_cost_allocation_journal_lines');
        $allocation = DB::table('wms_cost_allocations')->where('stock_movement_id', $movement->id)->where('status', '!=', 'REVERSED')
            ->selectRaw('COUNT(*) AS allocation_count')
            ->selectRaw('SUM(CASE WHEN cost_status = "PENDING" THEN 1 ELSE 0 END) AS pending_count')
            ->selectRaw('SUM(CASE WHEN journal_entry_id IS NULL THEN 1 ELSE 0 END) AS unlinked_count')
            ->first();

        $lineProof = $this->lineProofIssues($movement->id);

        return [
            'movement_id' => $movement->id,
            'allocation_count' => (int) ($allocation->allocation_count ?? 0),
            'pending_count' => (int) ($allocation->pending_count ?? 0),
            'unlinked_count' => (int) ($allocation->unlinked_count ?? 0),
            'source_ready' => $this->hasSourceIdentity($movement),
            ...InventoryPostingPreflight::evaluate([
                'movement_status' => $movement->status,
                'direction' => $movement->direction,
                'inventory_account_ready' => $this->isInventoryAccountReady($item?->inventoryAccount),
                'cogs_account_ready' => $this->isCogsAccountReady($item?->cogsAccount),
                'allocation_count' => $allocation->allocation_count ?? 0,
                'pending_count' => $allocation->pending_count ?? 0,
                'unlinked_count' => $allocation->unlinked_count ?? 0,
                'line_proof_ready' => $lineProofAvailable && (int) ($allocation->allocation_count ?? 0) > 0 && $lineProof['unlinked'] === 0 && $lineProof['mismatched'] === 0,
                'source_ready' => $this->hasSourceIdentity($movement),
            ]),
        ];
    }

    private function hasSourceIdentity(StockMovement $movement): bool
    {
        return collect([
            $movement->source_type,
            $movement->source_id,
            $movement->source_reference,
        ])->every(fn ($value): bool => filled(trim((string) $value)));
    }

    private function lineProofIssues(int $movementId): array
    {
        if (! Schema::hasTable('wms_cost_allocation_journal_lines')) {
            return ['unlinked' => 0, 'mismatched' => 0];
        }

        $base = DB::table('wms_cost_allocations as allocations')->where('allocations.stock_movement_id', $movementId)->where('allocations.status', '!=', 'REVERSED');
        $unlinked = (clone $base)->leftJoin('wms_cost_allocation_journal_lines as links', 'links.allocation_id', '=', 'allocations.id')->whereNull('links.id')->count('allocations.id');
        $mismatched = (clone $base)->join('wms_cost_allocation_journal_lines as links', 'links.allocation_id', '=', 'allocations.id')->leftJoin('journal_entry_lines as lines', 'lines.id', '=', 'links.journal_entry_line_id')->where(fn ($query) => $query->whereNull('lines.id')->orWhereNull('links.identity_key')->orWhereNull('allocations.journal_entry_id')->orWhereColumn('lines.journal_entry_id', '!=', 'allocations.journal_entry_id')->orWhereColumn('links.revision', '!=', 'allocations.revision'))->distinct()->count('allocations.id');

        return ['unlinked' => $unlinked, 'mismatched' => $mismatched];
    }

    private function lineMismatchedCount($movementIds): int
    {
        return DB::table('wms_cost_allocations as allocations')->join('wms_cost_allocation_journal_lines as links', 'links.allocation_id', '=', 'allocations.id')->leftJoin('journal_entry_lines as lines', 'lines.id', '=', 'links.journal_entry_line_id')->whereIn('allocations.stock_movement_id', $movementIds)->where('allocations.status', '!=', 'REVERSED')->where(fn ($query) => $query->whereNull('lines.id')->orWhereNull('links.identity_key')->orWhereNull('allocations.journal_entry_id')->orWhereColumn('lines.journal_entry_id', '!=', 'allocations.journal_entry_id')->orWhereColumn('links.revision', '!=', 'allocations.revision'))->distinct()->count('allocations.id');
    }

    private function isInventoryAccountReady($account): bool
    {
        return (bool) $account && $account->is_active && $account->is_postable && $account->control_account_type === 'INVENTORY';
    }

    private function isCogsAccountReady($account): bool
    {
        return (bool) $account && $account->is_active && $account->is_postable && $account->control_account_type === null && $account->type?->code === 'EXPENSE';
    }
}
