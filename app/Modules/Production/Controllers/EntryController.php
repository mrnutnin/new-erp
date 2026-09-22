<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\ProductionOrderService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class EntryController extends Controller
{
    public function __invoke(Request $request, ProductionOrderService $orders): View
    {
        $branch = $request->attributes->get('selectedBranch');
        $warehouse = $request->attributes->get('selectedWarehouse');
        $base = ProductionOrder::query()->where('branch_id', $branch->id);
        $released = (clone $base)->with(['materials.item', 'materials.uom'])->where('status', 'RELEASED')->latest('id')->limit(100)->get();
        $readyToReceive = DB::table('production_orders')
            ->join('production_order_events', 'production_order_events.production_order_id', '=', 'production_orders.id')
            ->join('wms_issue_documents', 'wms_issue_documents.id', '=', 'production_order_events.source_id')
            ->where('production_orders.branch_id', $branch->id)
            ->where('production_orders.status', 'IN_PROGRESS')
            ->where('production_order_events.event_type', 'material_issue_created')
            ->where('wms_issue_documents.status', 'POSTED')
            ->distinct('production_orders.id')
            ->count('production_orders.id');

        return view('Production::dashboard', [
            'branch' => $branch,
            'warehouse' => $warehouse,
            'metrics' => [
                'draft' => (clone $base)->where('status', 'DRAFT')->count(),
                'in_progress' => (clone $base)->where('status', 'IN_PROGRESS')->count(),
                'due_soon' => (clone $base)->whereIn('status', ['DRAFT', 'RELEASED', 'IN_PROGRESS'])->whereBetween('required_delivery_date', [today(), today()->addDays(7)])->count(),
                'overdue' => (clone $base)->whereIn('status', ['DRAFT', 'RELEASED', 'IN_PROGRESS'])->whereDate('required_delivery_date', '<', today())->count(),
                'shortage' => $released->filter(fn (ProductionOrder $order): bool => ! ($orders->materialReadiness($order)['ready'] ?? false))->count(),
                'ready_to_receive' => $readyToReceive,
            ],
        ]);
    }
}
