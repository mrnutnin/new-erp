<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockCostLayer;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Services\InventoryCostAllocationService;
use App\Modules\Wms\Services\StockBalanceService;
use App\Modules\Wms\Support\WmsDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        $query = Item::query()->leftJoinSub($valuation, 'valuation', fn ($join) => $join->on('valuation.item_id', '=', 'wms_items.id'))
            ->leftJoin('wms_uoms', 'wms_uoms.id', '=', 'wms_items.base_uom_id')
            ->where('wms_items.is_active', true)
            ->selectRaw('wms_items.id, wms_items.code, wms_items.name, wms_uoms.code AS uom_code, COALESCE(valuation.final_quantity, 0) AS on_hand, CASE WHEN COALESCE(valuation.final_quantity, 0) = 0 THEN 0 ELSE COALESCE(valuation.final_value, 0) / valuation.final_quantity END AS average_unit_cost, COALESCE(valuation.final_value, 0) AS inventory_value');
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
        $allocationCosts = CostAllocation::query()
            ->where('status', 'POSTED')
            ->where('cost_status', 'FINAL')
            ->selectRaw('stock_movement_id, SUM(value) AS total_value, SUM(ABS(value)) / NULLIF(SUM(quantity), 0) AS unit_cost')
            ->groupBy('stock_movement_id');
        $movementValue = 'COALESCE(ABS(allocation_cost.total_value), CASE WHEN movement_cost.unit_cost IS NOT NULL THEN wms_stock_movements.base_quantity * movement_cost.unit_cost WHEN allocation_cost.unit_cost IS NOT NULL THEN wms_stock_movements.base_quantity * allocation_cost.unit_cost END)';
        $query = StockMovement::query()->with(['uom:id,code'])
            ->leftJoinSub($costs, 'movement_cost', fn ($join) => $join->on('movement_cost.source_movement_id', '=', 'wms_stock_movements.id'))
            ->leftJoinSub($allocationCosts, 'allocation_cost', fn ($join) => $join->on('allocation_cost.stock_movement_id', '=', 'wms_stock_movements.id'))
            ->where('wms_stock_movements.warehouse_id', $warehouse?->id)->where('wms_stock_movements.status', 'POSTED')->when($itemId, fn ($q) => $q->where('wms_stock_movements.item_id', $itemId), fn ($q) => $q->whereRaw('1 = 0'))->when($dateFrom, fn ($q) => $q->whereDate('wms_stock_movements.business_date', '>=', $dateFrom))->whereDate('wms_stock_movements.business_date', '<=', $dateTo)
            ->selectRaw('wms_stock_movements.*, COALESCE(movement_cost.unit_cost, allocation_cost.unit_cost) AS unit_cost')
            ->selectRaw("{$movementValue} AS movement_value")
            ->selectRaw("SUM(CASE WHEN wms_stock_movements.direction = 'IN' THEN COALESCE({$movementValue}, 0) ELSE -COALESCE({$movementValue}, 0) END) OVER (ORDER BY wms_stock_movements.business_date, wms_stock_movements.id ROWS UNBOUNDED PRECEDING) AS running_value")
            ->orderBy('wms_stock_movements.business_date')->orderBy('wms_stock_movements.id');
        // StockBalance is keyed by the item's base UOM. Passing a null UOM
        // here silently returned an empty balance even though movements were
        // present, which made the Stock Card summary cards show zero.
        $uomId = $itemId ? Item::query()->whereKey($itemId)->value('base_uom_id') : null;
        $openingBalance = $itemId && $warehouse && $uomId && $openingDate
            ? $balances->forItem((int) $warehouse->id, $itemId, (int) $uomId, $openingDate)
            : ['on_hand' => '0.00000000', 'reserved' => '0.00000000', 'available' => '0.00000000'];
        $balance = $itemId && $warehouse && $uomId
            ? $balances->forItem((int) $warehouse->id, $itemId, (int) $uomId, $dateTo)
            : ['on_hand' => '0.00000000', 'reserved' => '0.00000000', 'available' => '0.00000000'];
        $valuation = $itemId && $warehouse
            ? $costing->historicalValuationQuery($dateTo, (int) $warehouse->id, $itemId)->first()
            : null;
        $inventoryValue = BigDecimal::of((string) ($valuation?->final_value ?? '0'));
        $finalQuantity = BigDecimal::of((string) ($valuation?->final_quantity ?? '0'));
        $balance['inventory_value'] = $inventoryValue->__toString();
        $balance['average_unit_cost'] = $finalQuantity->isZero() ? '0' : $inventoryValue->dividedBy($finalQuantity, 8, RoundingMode::HALF_UP)->__toString();
        $openingQuantity = (string) ($openingBalance['on_hand'] ?? '0');
        $query->selectRaw('( ? + SUM(CASE WHEN wms_stock_movements.direction = \'IN\' THEN wms_stock_movements.base_quantity ELSE -wms_stock_movements.base_quantity END) OVER (ORDER BY wms_stock_movements.business_date, wms_stock_movements.id ROWS UNBOUNDED PRECEDING) ) AS running_balance', [$openingQuantity]);
        foreach (['on_hand', 'reserved', 'available', 'average_unit_cost', 'inventory_value'] as $key) {
            $balance[$key] = WmsDecimal::format($balance[$key] ?? null);
            $openingBalance[$key] = WmsDecimal::format($openingBalance[$key] ?? null);
        }
        $format = app(GlobalSettings::class)->value('date_format') ?: 'd/m/Y';

        return DataTables::eloquent($query)->editColumn('business_date', fn ($r) => $r->business_date?->format($format) ?: '-')->addColumn('movement_datetime', fn ($r) => $r->posted_at?->format($format.' H:i') ?: ($r->business_date?->format($format) ?: '-'))->addColumn('direction_label', fn ($r) => $r->direction === 'IN' ? 'เข้า' : 'ออก')->addColumn('movement_type_label', fn ($r) => ['RECEIPT' => 'รับเข้า', 'ISSUE' => 'จ่ายออก', 'TRANSFER' => 'โอน', 'ADJUSTMENT' => 'ปรับปรุง', 'COUNT' => 'ตรวจนับ'][$r->movement_type] ?? $r->movement_type)->addColumn('uom_label', fn ($r) => $r->uom?->code ?: '-')->addColumn('base_quantity_label', fn ($r) => WmsDecimal::format($r->base_quantity))->addColumn('unit_cost_label', fn ($r) => WmsDecimal::format($r->unit_cost))->addColumn('movement_total', fn ($r) => WmsDecimal::format($r->movement_value))->addColumn('running_balance', fn ($r) => WmsDecimal::format($r->running_balance))->editColumn('running_value', fn ($r) => WmsDecimal::format($r->running_value))->with(['balance' => $balance, 'opening_balance' => $openingBalance])->toJson();
    }

    private function warehouses(Request $request)
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', $request->attributes->get('selectedBranch')->id)
            ->orderBy('name')->get(['warehouses.id', 'warehouses.code', 'warehouses.name']);
    }
}
