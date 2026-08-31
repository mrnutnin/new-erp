<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockCostLayer;
use App\Modules\Wms\Services\InventoryCostAllocationService;
use App\Modules\Wms\Services\InventoryPostingPreflightService;
use App\Modules\Wms\Services\InventoryReconciliationService;
use App\Modules\Wms\Services\RecostQueueHealth;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class StockValuationController extends Controller
{
    public function index(Request $request): View
    {
        return view('Wms::stock-valuation.index', ['warehouse' => $request->attributes->get('selectedWarehouse'), 'warehouses' => $this->warehouses($request)]);
    }

    public function show(Request $request, Item $item): View
    {
        abort_unless($item->is_active, 404);

        return view('Wms::stock-valuation.show', [
            'warehouse' => $request->attributes->get('selectedWarehouse'),
            'item' => $item,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $itemId = $request->integer('item_id');
        $pending = 'COALESCE(pending.pending_count, 0)';

        $query = StockBalance::query()
            ->join('wms_items', 'wms_items.id', '=', 'wms_stock_balances.item_id')
            ->leftJoin('wms_uoms', 'wms_uoms.id', '=', 'wms_stock_balances.uom_id')
            ->leftJoinSub(
                StockCostLayer::query()
                    ->selectRaw('warehouse_id, item_id, uom_id, COUNT(*) AS pending_count')
                    ->where('warehouse_id', $warehouse?->id)
                    ->where('cost_status', 'PENDING')
                    ->where('remaining_quantity', '>', 0)
                    ->groupBy('warehouse_id', 'item_id', 'uom_id'),
                'pending',
                fn ($join) => $join->on('pending.warehouse_id', '=', 'wms_stock_balances.warehouse_id')
                    ->on('pending.item_id', '=', 'wms_stock_balances.item_id')
                    ->on('pending.uom_id', '=', 'wms_stock_balances.uom_id')
            )
            ->where('wms_stock_balances.warehouse_id', $warehouse?->id)
            ->when($itemId, fn ($query) => $query->where('wms_stock_balances.item_id', $itemId))
            ->select([
                'wms_stock_balances.id', 'wms_stock_balances.item_id', 'wms_stock_balances.on_hand', 'wms_stock_balances.reserved',
                'wms_stock_balances.available', 'wms_stock_balances.inventory_value',
                'wms_stock_balances.average_unit_cost', 'wms_items.code AS item_code',
                'wms_items.name AS item_name', 'wms_uoms.code AS uom_code',
                'pending.pending_count',
            ]);

        return DataTables::eloquent($query)
            ->addColumn('item_label', fn ($row) => trim($row->item_code.' · '.$row->item_name))
            ->addColumn('uom_label', fn ($row) => $row->uom_code ?: '-')
            ->addColumn('costing_label', fn ($row) => $row->average_unit_cost !== null ? 'คำนวณแล้ว' : 'รอต้นทุน')
            ->addColumn('recost_label', fn ($row) => (int) ($row->pending_count ?? 0) > 0 ? 'รอคำนวณใหม่' : 'ปกติ')
            ->addColumn('detail_url', fn ($row) => route('wms.stock-valuation.show', $row->item_id))
            ->editColumn('on_hand', fn ($row) => WmsDecimal::format($row->on_hand))
            ->editColumn('reserved', fn ($row) => WmsDecimal::format($row->reserved))
            ->editColumn('available', fn ($row) => WmsDecimal::format($row->available))
            ->editColumn('average_unit_cost', fn ($row) => WmsDecimal::format($row->average_unit_cost))
            ->editColumn('inventory_value', fn ($row) => WmsDecimal::format($row->inventory_value))
            ->addColumn('pending_count', fn ($row) => (int) ($row->pending_count ?? 0))
            ->toJson();
    }

    public function layersData(Request $request, Item $item): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $query = StockCostLayer::query()
            ->leftJoin('wms_stock_movements', 'wms_stock_movements.id', '=', 'wms_stock_cost_layers.source_movement_id')
            ->where('wms_stock_cost_layers.warehouse_id', $warehouse?->id)
            ->where('wms_stock_cost_layers.item_id', $item->id)
            ->select('wms_stock_cost_layers.*', 'wms_stock_movements.source_reference');

        return DataTables::eloquent($query)
            ->editColumn('business_date', fn ($row) => $row->business_date?->format((string) app(GlobalSettings::class)->value('date_format')) ?: '-')
            ->editColumn('original_quantity', fn ($row) => WmsDecimal::format($row->original_quantity))
            ->editColumn('remaining_quantity', fn ($row) => WmsDecimal::format($row->remaining_quantity))
            ->editColumn('unit_cost', fn ($row) => WmsDecimal::format($row->unit_cost))
            ->addColumn('method_label', fn ($row) => $row->method === 'FIFO' ? 'FIFO' : 'AVG')
            ->addColumn('status_label', fn ($row) => $row->cost_status === 'PENDING' ? 'รอคำนวณ' : 'ยืนยันแล้ว')
            ->toJson();
    }

    public function historicalData(Request $request, InventoryCostAllocationService $costing): JsonResponse
    {
        $values = $request->validate([
            'as_of_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'item_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $warehouse = $request->attributes->get('selectedWarehouse');
        $itemId = (int) ($values['item_id'] ?? 0) ?: null;
        $base = $costing->historicalValuationQuery($values['as_of_date'], (int) $warehouse->id, $itemId)->toBase();
        $query = DB::query()->fromSub($base, 'valuation')
            ->join('wms_items', 'wms_items.id', '=', 'valuation.item_id')
            ->leftJoin('wms_uoms', 'wms_uoms.id', '=', 'wms_items.base_uom_id')
            ->select([
                'valuation.item_id', 'valuation.final_quantity', 'valuation.final_value',
                'valuation.pending_value', 'valuation.pending_count',
                'wms_items.code AS item_code', 'wms_items.name AS item_name', 'wms_uoms.code AS uom_code',
            ]);

        return DataTables::query($query)
            ->addColumn('item_label', fn ($row) => trim($row->item_code.' · '.$row->item_name))
            ->addColumn('uom_label', fn ($row) => $row->uom_code ?: '-')
            ->addColumn('detail_url', fn ($row) => route('wms.stock-valuation.show', $row->item_id))
            ->addColumn('status_label', fn ($row) => (int) $row->pending_count > 0 ? 'รอ Recost' : 'Final')
            ->editColumn('final_quantity', fn ($row) => WmsDecimal::format($row->final_quantity))
            ->editColumn('final_value', fn ($row) => WmsDecimal::format($row->final_value))
            ->editColumn('pending_value', fn ($row) => WmsDecimal::format($row->pending_value))
            ->addColumn('pending_count', fn ($row) => (int) $row->pending_count)
            ->toJson();
    }

    public function historicalReconciliationData(Request $request, InventoryReconciliationService $reconciliation): JsonResponse
    {
        $values = $request->validate(['as_of_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'item_id' => ['nullable', 'integer', 'min:1']]);
        $warehouse = $request->attributes->get('selectedWarehouse');
        $query = $reconciliation->historicalQuery($values['as_of_date'], (int) $warehouse->id, (int) ($values['item_id'] ?? 0) ?: null);

        return DataTables::query($query)
            ->addColumn('item_label', fn ($row) => trim($row->item_code.' · '.$row->item_name))
            ->addColumn('detail_url', fn ($row) => route('wms.stock-valuation.show', $row->item_id))
            ->addColumn('status_label', fn ($row) => (int) $row->pending_count > 0 ? 'รอ Recost' : ((int) $row->unlinked_count > 0 || (float) $row->difference !== 0.0 || (float) $row->balance_difference !== 0.0 ? 'ต้องตรวจสอบ' : 'ตรงกัน'))
            ->editColumn('final_value', fn ($row) => WmsDecimal::format($row->final_value))
            ->editColumn('balance_value', fn ($row) => WmsDecimal::format($row->balance_value))
            ->editColumn('gl_value', fn ($row) => WmsDecimal::format($row->gl_value))
            ->editColumn('difference', fn ($row) => WmsDecimal::format($row->difference))
            ->editColumn('balance_difference', fn ($row) => WmsDecimal::format($row->balance_difference))
            ->editColumn('pending_value', fn ($row) => WmsDecimal::format($row->pending_value))
            ->addColumn('pending_count', fn ($row) => (int) $row->pending_count)
            ->addColumn('unlinked_count', fn ($row) => (int) $row->unlinked_count)
            ->toJson();
    }

    public function preflightSummary(Request $request, InventoryPostingPreflightService $preflight): JsonResponse
    {
        return response()->json($preflight->summary((int) $request->attributes->get('selectedWarehouse')->id));
    }

    public function recostHealth(Request $request, RecostQueueHealth $health): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;

        return response()->json([
            'sla_minutes' => $health->staleMinutes(),
            'summary' => $health->summary($warehouseId),
            'items' => $health->recentOpen($warehouseId),
        ]);
    }

    public function retryRecost(Request $request, RecostQueueHealth $health): JsonResponse
    {
        $values = $request->validate(['request_id' => ['required', 'integer', 'min:1']]);
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $health->retry((int) $values['request_id'], $warehouseId);

        return response()->json(['status' => true, 'msg' => 'ส่งรายการ Recost กลับเป็นรอประมวลผลแล้ว ระบบจะหยิบเข้าคิวตามรอบถัดไป']);
    }

    private function warehouses(Request $request)
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', $request->attributes->get('selectedBranch')->id)
            ->orderBy('name')->get(['warehouses.id', 'warehouses.code', 'warehouses.name']);
    }
}
