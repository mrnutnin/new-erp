<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockCostLayer;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Services\InventoryCostAllocationService;
use App\Modules\Wms\Services\StockBalanceProjectionReconciliationService;
use App\Modules\Wms\Services\StockBalanceService;
use App\Modules\Wms\Support\WmsDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class StockController extends Controller
{
    public function index(Request $request): View
    {
        return view('Wms::stock.index', ['warehouse' => $request->attributes->get('selectedWarehouse'), 'warehouses' => $this->warehouses($request)]);
    }

    public function show(Request $request, Item $item): View
    {
        abort_unless($item->is_active, 404);

        return view('Wms::stock.show', [
            'warehouse' => $request->attributes->get('selectedWarehouse'),
            'item' => $item,
        ]);
    }

    public function reconciliation(Request $request, Item $item, StockBalanceProjectionReconciliationService $reconciliation): JsonResponse
    {
        $values = $request->validate([
            'uom_id' => ['required', 'integer', 'min:1'],
            'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ]);
        $warehouse = $request->attributes->get('selectedWarehouse');

        return response()->json($reconciliation->check(
            (int) $warehouse->id,
            (int) $item->id,
            (int) $values['uom_id'],
            $values['as_of'] ?? null,
        ));
    }

    public function summary(Request $request, InventoryCostAllocationService $costing): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $branch = $request->attributes->get('selectedBranch');
        $businessToday = now('Asia/Bangkok')->toDateString();
        $values = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$businessToday],
            'stock_status' => ['nullable', 'in:in_stock,out_of_stock,negative'],
        ]);
        $asOf = $values['as_of'] ?? now()->toDateString();
        $valuation = $costing->historicalValuationQuery($asOf, (int) $warehouse?->id)->toBase();
        $totals = DB::query()->fromSub($valuation, 'valuation_totals')->selectRaw('COALESCE(SUM(final_quantity), 0) AS on_hand')->selectRaw('COALESCE(SUM(final_value), 0) AS inventory_value')->first();
        $stockTotals = StockBalance::query()->where('warehouse_id', $warehouse?->id)->selectRaw('COALESCE(SUM(reserved), 0) AS reserved')->selectRaw('COALESCE(SUM(available), 0) AS available')->first();
        $totalQuantity = BigDecimal::of((string) ($totals?->on_hand ?? '0'));
        $totalValue = BigDecimal::of((string) ($totals?->inventory_value ?? '0'));
        $summary = [
            'on_hand' => WmsDecimal::format($totalQuantity->__toString()),
            'reserved' => WmsDecimal::format($stockTotals?->reserved ?? '0'),
            'available' => WmsDecimal::format($stockTotals?->available ?? '0'),
            'average_unit_cost' => WmsDecimal::format(($totalQuantity->isZero() ? BigDecimal::zero() : $totalValue->dividedBy($totalQuantity, 8, RoundingMode::HALF_UP))->__toString()),
            'inventory_value' => WmsDecimal::format(($totalQuantity->isZero() ? BigDecimal::zero() : $totalValue)->__toString()),
        ];

        $query = Item::query()->leftJoinSub($valuation, 'valuation', fn ($join) => $join->on('valuation.item_id', '=', 'wms_items.id'))
            ->leftJoin('wms_uoms', 'wms_uoms.id', '=', 'wms_items.base_uom_id')
            ->where('wms_items.is_active', true)
            ->selectRaw('wms_items.id, wms_items.code, wms_items.name, wms_uoms.code AS uom_code, COALESCE(valuation.final_quantity, 0) AS on_hand, CASE WHEN COALESCE(valuation.final_quantity, 0) = 0 THEN 0 ELSE COALESCE(valuation.final_value, 0) / valuation.final_quantity END AS average_unit_cost, CASE WHEN COALESCE(valuation.final_quantity, 0) = 0 THEN 0 ELSE COALESCE(valuation.final_value, 0) END AS inventory_value');
        if (($values['stock_status'] ?? null) === 'in_stock') {
            $query->whereRaw('COALESCE(valuation.final_quantity, 0) > 0');
        } elseif (($values['stock_status'] ?? null) === 'out_of_stock') {
            $query->whereRaw('COALESCE(valuation.final_quantity, 0) = 0');
        } elseif (($values['stock_status'] ?? null) === 'negative') {
            $query->whereRaw('COALESCE(valuation.final_quantity, 0) < 0');
        }

        return DataTables::eloquent($query)
            ->addColumn('item_label', fn ($row) => trim($row->code.' · '.$row->name))
            ->filter(function ($query) use ($request): void {
                $keyword = trim((string) $request->input('search.value'));
                if ($keyword !== '') {
                    $query->where(function ($search) use ($keyword): void {
                        $search->where('wms_items.code', 'like', "%{$keyword}%")
                            ->orWhere('wms_items.name', 'like', "%{$keyword}%");
                    });
                }
            }, true)
            ->addColumn('uom_label', fn ($row) => $row->uom_code ?: '-')
            ->addColumn('detail_url', fn ($row) => route('wms.stock.show', $row->id).'?'.http_build_query(['branch_id' => $branch?->id, 'warehouse_id' => $warehouse?->id, 'item_id' => $row->id]))
            ->editColumn('on_hand', fn ($row) => WmsDecimal::format($row->on_hand))
            ->editColumn('average_unit_cost', fn ($row) => WmsDecimal::format($row->average_unit_cost))
            ->editColumn('inventory_value', fn ($row) => WmsDecimal::format($row->inventory_value))
            ->with(['balance' => $summary])
            ->toJson();
    }

    public function itemOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = Item::query()->with('baseUom:id,code,name')->where('is_active', true)->where('is_stock_item', true)->when($q, fn ($x) => $x->where(fn ($y) => $y->where('code', 'like', "%$q%")->orWhere('name', 'like', "%$q%")))->orderBy('code')->forPage(max(1, $request->integer('page', 1)), 31)->get(['id', 'code', 'name', 'base_uom_id']);

        return response()->json(['results' => $rows->take(30)->map(fn ($r) => ['id' => $r->id, 'text' => $r->code.' · '.$r->name, 'uom_id' => $r->base_uom_id, 'uom_label' => $r->baseUom?->code ?: $r->baseUom?->name ?: '-'])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function data(Request $request, StockBalanceService $balances, InventoryCostAllocationService $costing): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $businessToday = now('Asia/Bangkok')->toDateString();
        $values = $request->validate([
            'item_id' => ['nullable', 'integer', 'min:1'],
            'date_from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$businessToday],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.$businessToday, 'after_or_equal:date_from'],
        ]);
        $itemId = (int) ($values['item_id'] ?? 0) ?: null;
        $dateFrom = $values['date_from'] ?? null;
        $dateTo = $values['date_to'] ?? $businessToday;
        $openingDate = $dateFrom ? CarbonImmutable::createFromFormat('!Y-m-d', $dateFrom)->subDay()->toDateString() : null;
        // Normal receipt/issue costing is represented by StockCostLayer, while
        // direct inventory adjustments deliberately write immutable cost
        // allocations without creating a layer.  The Stock Card must read
        // both sources or adjustment rows show quantity but no value/cost.
        $costs = StockCostLayer::query()->selectRaw('source_movement_id, MAX(unit_cost) AS unit_cost')->groupBy('source_movement_id');
        $allocationCosts = $costing->canonicalAsOf($dateTo)
            ->join('wms_stock_movements AS allocation_movements', 'allocation_movements.id', '=', 'wms_cost_allocations.stock_movement_id')
            ->whereIn('wms_cost_allocations.status', ['POSTED', 'PENDING'])
            ->where('wms_cost_allocations.cost_status', 'FINAL')
            // RECOST value is absolute and its direction is the inventory
            // correction direction.  Convert it back to the movement-value
            // sign before combining it with the original allocation.
            ->selectRaw('stock_movement_id, SUM(CASE WHEN allocation_type = "RECOST" THEN CASE WHEN allocation_movements.direction = wms_cost_allocations.direction THEN ABS(wms_cost_allocations.value) ELSE -ABS(wms_cost_allocations.value) END WHEN wms_cost_allocations.direction = "IN" THEN ABS(wms_cost_allocations.value) ELSE -ABS(wms_cost_allocations.value) END) AS total_value')
            ->groupBy('stock_movement_id');
        // Prefer the effective allocation total when it exists. This preserves
        // a legitimate zero-cost movement and avoids averaging duplicate/zero
        // allocations into an incorrect unit cost. Layer cost is fallback for
        // movements whose cost allocation has not been written yet. Transfer
        // bridges and reversed Issue Returns can be PENDING without Journal
        // proof while still having FINAL cost and a Posted movement.
        $movementValue = 'CASE WHEN allocation_cost.total_value IS NOT NULL THEN ABS(allocation_cost.total_value) WHEN movement_cost.unit_cost IS NOT NULL THEN wms_stock_movements.base_quantity * movement_cost.unit_cost END';
        $movementUnitCost = 'CASE WHEN wms_stock_movements.base_quantity = 0 THEN NULL WHEN allocation_cost.total_value IS NOT NULL THEN ABS(allocation_cost.total_value) / wms_stock_movements.base_quantity WHEN movement_cost.unit_cost IS NOT NULL THEN movement_cost.unit_cost END';
        $documentNumbers = DB::table('wms_inventory_adjustment_documents AS documents')
            ->selectRaw("'WMS_PRODUCTION_RECEIPT' AS source_type, CAST(documents.id AS CHAR) AS source_id, documents.document_number")
            ->unionAll(DB::table('wms_inventory_adjustments AS lines')
                ->join('wms_inventory_adjustment_documents AS documents', 'documents.id', '=', 'lines.document_id')
                ->selectRaw("'INVENTORY' AS source_type, CONCAT('adjustment:', lines.id) AS source_id, documents.document_number"));
        $uomId = $itemId ? Item::query()->whereKey($itemId)->value('base_uom_id') : null;
        $openingBalance = $itemId && $warehouse && $uomId && $openingDate
            ? $balances->forItem((int) $warehouse->id, $itemId, (int) $uomId, $openingDate)
            : ['on_hand' => '0.00000000', 'reserved' => '0.00000000', 'available' => '0.00000000'];
        $openingQuantity = (string) ($openingBalance['on_hand'] ?? '0');
        $openingValuation = $itemId && $warehouse && $openingDate
            ? $costing->historicalValuationQuery($openingDate, (int) $warehouse->id, $itemId)->first()
            : null;
        $openingValue = BigDecimal::of((string) ($openingValuation?->final_value ?? '0'));
        if (BigDecimal::of($openingQuantity)->isZero()) {
            $openingValue = BigDecimal::zero();
        }
        $openingBalance['inventory_value'] = $openingValue->__toString();
        $openingBalance['average_unit_cost'] = BigDecimal::of($openingQuantity)->isZero()
            ? '0'
            : $openingValue->dividedBy($openingQuantity, 8, RoundingMode::HALF_UP)->__toString();
        $movementRows = DB::table('wms_stock_movements')
            ->leftJoinSub($costs, 'movement_cost', fn ($join) => $join->on('movement_cost.source_movement_id', '=', 'wms_stock_movements.id'))
            ->leftJoinSub($allocationCosts, 'allocation_cost', fn ($join) => $join->on('allocation_cost.stock_movement_id', '=', 'wms_stock_movements.id'))
            ->leftJoinSub($documentNumbers, 'source_documents', fn ($join) => $join->on('source_documents.source_type', '=', 'wms_stock_movements.source_type')->on('source_documents.source_id', '=', 'wms_stock_movements.source_id'))
            ->leftJoin('wms_uoms', 'wms_uoms.id', '=', 'wms_stock_movements.uom_id')
            ->where('wms_stock_movements.warehouse_id', $warehouse?->id)->where('wms_stock_movements.status', 'POSTED')->when($itemId, fn ($q) => $q->where('wms_stock_movements.item_id', $itemId), fn ($q) => $q->whereRaw('1 = 0'))->when($dateFrom, fn ($q) => $q->where('wms_stock_movements.business_date', '>=', $dateFrom))->where('wms_stock_movements.business_date', '<', CarbonImmutable::createFromFormat('!Y-m-d', $dateTo)->addDay()->toDateString())
            ->select(['wms_stock_movements.id', 'wms_stock_movements.movement_type', 'wms_stock_movements.direction', 'wms_stock_movements.base_quantity', 'wms_stock_movements.business_date', 'wms_stock_movements.metadata', 'wms_uoms.code AS uom_code'])
            ->selectRaw('COALESCE(source_documents.document_number, wms_stock_movements.source_reference) AS document_number')
            ->selectRaw('COALESCE(source_documents.document_number, wms_stock_movements.source_reference) AS source_reference')
            ->selectRaw("{$movementUnitCost} AS unit_cost")
            ->selectRaw("COALESCE({$movementValue}, 0) AS movement_value");

        // A windowed cumulative sum cannot restart after a terminal OUT. Keep
        // the reset in SQL so a large Stock Card is still paginated by
        // DataTables instead of loading the whole movement history in PHP.
        $quantityStates = DB::query()->fromSub($movementRows, 'movement_rows')
            ->select('movement_rows.*')
            ->selectRaw("? + SUM(CASE WHEN direction = 'IN' THEN base_quantity ELSE -base_quantity END) OVER (ORDER BY business_date, id ROWS UNBOUNDED PRECEDING) AS running_quantity", [$openingQuantity]);
        $terminalStates = DB::query()->fromSub($quantityStates, 'quantity_states')
            ->select('quantity_states.*')
            ->selectRaw('CASE WHEN ROUND(running_quantity, 8) = 0 THEN 1 ELSE 0 END AS terminal_marker');
        $resetStates = DB::query()->fromSub($terminalStates, 'terminal_states')
            ->select('terminal_states.*')
            ->selectRaw('(SUM(terminal_marker) OVER (ORDER BY business_date, id ROWS UNBOUNDED PRECEDING) - terminal_marker) AS reset_group');
        $valueStates = DB::query()->fromSub($resetStates, 'reset_states')
            ->select('reset_states.*')
            ->selectRaw("CASE WHEN terminal_marker = 1 THEN 0 WHEN reset_group = 0 THEN ? + SUM(CASE WHEN direction = 'IN' THEN movement_value ELSE -movement_value END) OVER (PARTITION BY reset_group ORDER BY business_date, id ROWS UNBOUNDED PRECEDING) ELSE SUM(CASE WHEN direction = 'IN' THEN movement_value ELSE -movement_value END) OVER (PARTITION BY reset_group ORDER BY business_date, id ROWS UNBOUNDED PRECEDING) END AS running_value_total", [$openingValue->__toString()]);
        $query = DB::query()->fromSub($valueStates, 'stock_card_rows')
            ->select('stock_card_rows.*')
            ->selectRaw('CASE WHEN ROUND(running_quantity, 8) = 0 THEN 0 ELSE running_value_total / running_quantity END AS running_average_unit_cost');
        // StockBalance is keyed by the item's base UOM. Passing a null UOM
        // here silently returned an empty balance even though movements were
        // present, which made the Stock Card summary cards show zero.
        $balance = $itemId && $warehouse && $uomId
            ? $balances->forItem((int) $warehouse->id, $itemId, (int) $uomId, $dateTo)
            : ['on_hand' => '0.00000000', 'reserved' => '0.00000000', 'available' => '0.00000000'];
        $valuation = $itemId && $warehouse
            ? $costing->historicalValuationQuery($dateTo, (int) $warehouse->id, $itemId)->first()
            : null;
        $inventoryValue = BigDecimal::of((string) ($valuation?->final_value ?? '0'));
        $finalQuantity = BigDecimal::of((string) ($valuation?->final_quantity ?? '0'));
        if ($finalQuantity->isZero()) {
            $inventoryValue = BigDecimal::zero();
        }
        $balance['inventory_value'] = $inventoryValue->__toString();
        $balance['average_unit_cost'] = $finalQuantity->isZero() ? '0' : $inventoryValue->dividedBy($finalQuantity, 8, RoundingMode::HALF_UP)->__toString();
        foreach (['on_hand', 'reserved', 'available', 'average_unit_cost', 'inventory_value'] as $key) {
            $balance[$key] = WmsDecimal::format($balance[$key] ?? null);
            $openingBalance[$key] = WmsDecimal::format($openingBalance[$key] ?? null);
        }
        $format = app(GlobalSettings::class)->value('date_format') ?: 'd/m/Y';

        return DataTables::query($query)->editColumn('business_date', fn ($r) => $r->business_date ? CarbonImmutable::parse($r->business_date)->format($format) : '-')->addColumn('movement_datetime', fn ($r) => $r->business_date ? CarbonImmutable::parse($r->business_date)->format($format) : '-')->addColumn('direction_label', fn ($r) => $r->direction === 'IN' ? 'เข้า' : 'ออก')->addColumn('movement_type_label', function ($r): string {
            $metadata = is_array($r->metadata) ? $r->metadata : json_decode((string) ($r->metadata ?? ''), true);
            $label = match ($r->movement_type) {
                'ISSUE' => $r->direction === 'IN' ? 'รับคืน' : 'จ่ายออก',
                'TRANSFER' => $r->direction === 'IN' ? 'โอนเข้า' : 'โอนออก',
                'RECEIPT' => $r->direction === 'IN' ? 'รับเข้า' : 'จ่ายออก',
                'ADJUSTMENT' => $r->direction === 'IN' ? 'ปรับปรุง (รับเข้า)' : 'ปรับปรุง (จ่ายออก)',
                'COUNT' => $r->direction === 'IN' ? 'ตรวจนับ (รับเข้า)' : 'ตรวจนับ (จ่ายออก)',
                default => $r->movement_type,
            };
            $reversalOf = is_array($metadata) ? ($metadata['reversal_of_movement_id'] ?? null) : null;

            return $reversalOf ? 'กลับรายการ · '.$label.' (จาก Movement #'.(int) $reversalOf.')' : $label;
        })->addColumn('uom_label', fn ($r) => $r->uom_code ?: '-')->addColumn('base_quantity_label', fn ($r) => WmsDecimal::format($r->base_quantity))->addColumn('unit_cost_label', fn ($r) => WmsDecimal::format($r->unit_cost))->addColumn('movement_total', fn ($r) => WmsDecimal::format($r->movement_value))->addColumn('running_balance', fn ($r) => WmsDecimal::format($r->running_quantity))->addColumn('running_quantity', fn ($r) => (string) $r->running_quantity)->addColumn('running_value_total', fn ($r) => (string) $r->running_value_total)->addColumn('running_average_unit_cost', fn ($r) => (string) $r->running_average_unit_cost)->editColumn('running_value', fn ($r) => WmsDecimal::format($r->running_value_total))->with(['balance' => $balance, 'opening_balance' => $openingBalance])->toJson();
    }

    private function warehouses(Request $request)
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', $request->attributes->get('selectedBranch')->id)
            ->orderBy('name')->get(['warehouses.id', 'warehouses.code', 'warehouses.name']);
    }
}
