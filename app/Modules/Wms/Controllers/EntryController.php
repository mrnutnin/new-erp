<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Wms\Models\CostRecalculationRequest;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\OpeningBalanceBatch;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockCountDocument;
use App\Modules\Wms\Models\StockMovement;
use App\Modules\Wms\Models\StockPolicy;
use App\Modules\Wms\Models\Transfer;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class EntryController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('Wms::dashboard', [
            'program' => $request->attributes->get('selectedProgram'),
            'warehouse' => $request->attributes->get('selectedWarehouse'),
        ]);
    }

    public function data(Request $request, string $section): JsonResponse
    {
        abort_unless(in_array($section, ['summary', 'work', 'trend', 'low-stock', 'movements'], true), 404);
        $warehouse = $request->attributes->get('selectedWarehouse');
        abort_unless($warehouse, 404);

        if (in_array($section, ['low-stock', 'movements'], true)) {
            return $section === 'low-stock' ? $this->lowStock($warehouse->id) : $this->movements($warehouse->id);
        }

        $key = 'wms:dashboard:'.$section.':warehouse:'.$warehouse->id.':user:'.$request->user()->id;
        return response()->json(Cache::remember($key, now()->addSeconds(30), fn (): array => match ($section) {
            'summary' => $this->summary((int) $warehouse->id),
            'work' => $this->work((int) $warehouse->id),
            'trend' => $this->trend((int) $warehouse->id),
        }));
    }

    private function summary(int $warehouseId): array
    {
        $stock = StockBalance::query()->where('warehouse_id', $warehouseId)
            ->selectRaw('COUNT(DISTINCT item_id) AS item_count, COALESCE(SUM(on_hand), 0) AS on_hand, COALESCE(SUM(reserved), 0) AS reserved, COALESCE(SUM(available), 0) AS available, COALESCE(SUM(inventory_value), 0) AS inventory_value')->first();
        $negative = StockBalance::query()->where('warehouse_id', $warehouseId)->where(fn ($q) => $q->where('on_hand', '<', 0)->orWhere('available', '<', 0))->count();

        return [
            'active_items' => Item::query()->where('is_active', true)->where('is_stock_item', true)->count(),
            'stocked_items' => (int) ($stock->item_count ?? 0),
            'on_hand' => WmsDecimal::format($stock->on_hand ?? 0),
            'reserved' => WmsDecimal::format($stock->reserved ?? 0),
            'available' => WmsDecimal::format($stock->available ?? 0),
            'inventory_value' => WmsDecimal::format($stock->inventory_value ?? 0),
            'negative_stock' => $negative,
            'pending_recost' => CostRecalculationRequest::query()->where('warehouse_id', $warehouseId)->open()->count(),
        ];
    }

    private function work(int $warehouseId): array
    {
        return [
            'issues' => IssueDocument::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'APPROVED'])->count(),
            'returns' => IssueReturn::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'APPROVED'])->count(),
            'stock_counts' => StockCountDocument::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'COUNTED', 'APPROVED'])->count(),
            'adjustments' => InventoryAdjustmentDocument::query()->where('warehouse_id', $warehouseId)->whereIn('status', ['DRAFT', 'APPROVED'])->count(),
            'transfers' => Transfer::query()->where(fn ($q) => $q->where('source_warehouse_id', $warehouseId)->orWhere('destination_warehouse_id', $warehouseId))->whereIn('status', ['DRAFT', 'DISPATCHED', 'PARTIALLY_ACCEPTED', 'REJECTED'])->count(),
            'opening_balances' => OpeningBalanceBatch::query()->where('warehouse_id', $warehouseId)->where('status', 'DRAFT')->count(),
            'recost' => CostRecalculationRequest::query()->where('warehouse_id', $warehouseId)->open()->count(),
        ];
    }

    private function trend(int $warehouseId): array
    {
        $start = today()->startOfMonth()->subMonths(5);
        $rows = StockMovement::query()->where('warehouse_id', $warehouseId)->where('status', 'POSTED')->whereDate('business_date', '>=', $start)
            ->selectRaw("DATE_FORMAT(business_date, '%Y-%m') AS period, direction, SUM(base_quantity) AS quantity")->groupBy('period', 'direction')->get()->groupBy('period');
        $labels = $in = $out = [];
        for ($i = 5; $i >= 0; $i--) {
            $month = today()->startOfMonth()->subMonths($i);
            $byDirection = $rows->get($month->format('Y-m'), collect())->keyBy('direction');
            $labels[] = $month->format('m/Y');
            $in[] = (float) ($byDirection->get('IN')?->quantity ?? 0);
            $out[] = (float) ($byDirection->get('OUT')?->quantity ?? 0);
        }

        return compact('labels', 'in', 'out');
    }

    private function lowStock(int $warehouseId): JsonResponse
    {
        $query = StockPolicy::query()->join('wms_items as i', 'i.id', '=', 'wms_stock_policies.item_id')
            ->leftJoin('wms_stock_balances as b', function ($join) use ($warehouseId): void {
                $join->on('b.item_id', '=', 'wms_stock_policies.item_id')->where('b.warehouse_id', '=', $warehouseId);
            })->where('wms_stock_policies.warehouse_id', $warehouseId)->where('wms_stock_policies.is_active', true)
            ->where('i.is_active', true)->whereRaw('COALESCE(b.available, 0) < wms_stock_policies.min_quantity')
            ->select('i.id', 'i.code', 'i.name')->selectRaw('COALESCE(b.available, 0) AS available, wms_stock_policies.min_quantity AS min_quantity, wms_stock_policies.max_quantity AS max_quantity, GREATEST(wms_stock_policies.max_quantity - COALESCE(b.available, 0), 0) AS recommended');

        return DataTables::eloquent($query)->addColumn('item_label', fn ($row) => $row->code.' · '.$row->name)
            ->editColumn('available', fn ($row) => WmsDecimal::format($row->available))->editColumn('min_quantity', fn ($row) => WmsDecimal::format($row->min_quantity))
            ->editColumn('max_quantity', fn ($row) => WmsDecimal::format($row->max_quantity))->editColumn('recommended', fn ($row) => WmsDecimal::format($row->recommended))->toJson();
    }

    private function movements(int $warehouseId): JsonResponse
    {
        $query = StockMovement::query()->join('wms_items as i', 'i.id', '=', 'wms_stock_movements.item_id')->where('wms_stock_movements.warehouse_id', $warehouseId)->where('wms_stock_movements.status', 'POSTED')
            ->select('wms_stock_movements.id', 'wms_stock_movements.business_date', 'wms_stock_movements.movement_type', 'wms_stock_movements.direction', 'wms_stock_movements.base_quantity', 'wms_stock_movements.source_reference', 'i.code as item_code', 'i.name as item_name')->latest('wms_stock_movements.id');

        return DataTables::eloquent($query)->addColumn('item_label', fn ($row) => $row->item_code.' · '.$row->item_name)
            ->editColumn('business_date', fn ($row) => Carbon::parse($row->business_date)->format('d/m/Y'))->addColumn('direction_label', fn ($row) => $row->direction === 'IN' ? 'เข้า' : 'ออก')
            ->editColumn('base_quantity', fn ($row) => WmsDecimal::format($row->base_quantity))->toJson();
    }
}
