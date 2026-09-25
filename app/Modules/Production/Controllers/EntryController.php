<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
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
        $user = $request->user();
        $workQueues = collect();
        if ($user->hasPermission('wms.issues.view')) {
            $issues = IssueDocument::query()->where('branch_id', $branch->id)->where('warehouse_id', $warehouse->id)->where('issue_type', 'PRODUCTION');
            foreach ([
                ['kind' => 'issue', 'permission' => 'wms.issues.approve', 'status' => 'DRAFT', 'document' => 'ใบเบิกวัตถุดิบ', 'stage' => 'ร่าง · รออนุมัติ', 'description' => 'ตรวจสอบและอนุมัติใบเบิกวัตถุดิบผลิต'],
                ['kind' => 'issue', 'permission' => 'wms.issues.post', 'status' => 'APPROVED', 'document' => 'ใบเบิกวัตถุดิบ', 'stage' => 'รอลง Stock และ GL', 'description' => 'ใบเบิกที่อนุมัติแล้ว รอลง Stock และบัญชี'],
            ] as $queue) {
                if ($user->hasPermission($queue['permission'])) {
                    $workQueues->push([...$queue, 'count' => (clone $issues)->where('status', $queue['status'])->count()]);
                }
            }
        }
        if ($user->hasPermission('wms.inventory-adjustments.view')) {
            $receipts = InventoryAdjustmentDocument::query()->where('branch_id', $branch->id)->where('warehouse_id', $warehouse->id)->where('document_context', 'PRODUCTION_RECEIPT');
            foreach ([
                ['kind' => 'receipt', 'permission' => 'wms.inventory-adjustments.approve', 'status' => 'DRAFT', 'document' => 'ใบรับผลิต', 'stage' => 'ร่าง · รออนุมัติ', 'description' => 'ตรวจสอบและอนุมัติใบรับสินค้าผลิตเสร็จ'],
                ['kind' => 'receipt', 'permission' => 'wms.inventory-adjustments.post', 'status' => 'APPROVED', 'document' => 'ใบรับผลิต', 'stage' => 'รอลง Stock และ GL', 'description' => 'ใบรับผลิตที่อนุมัติแล้ว รอลง Stock และบัญชี'],
            ] as $queue) {
                if ($user->hasPermission($queue['permission'])) {
                    $workQueues->push([...$queue, 'count' => (clone $receipts)->where('status', $queue['status'])->count()]);
                }
            }
        }
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
            'workQueues' => $workQueues,
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
