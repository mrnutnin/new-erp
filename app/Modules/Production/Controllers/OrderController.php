<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\FileStorageService;
use App\Modules\Pos\Models\SalesOrderLine;
use App\Modules\Production\Models\BomRevision;
use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Models\ProductionOrderIssue;
use App\Modules\Production\Models\ProductionOrderOperation;
use App\Modules\Production\Notifications\ProductionIssueReportedNotification;
use App\Modules\Production\Services\ProductionOrderService;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\IssueLine;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Wms\Models\IssueReturnLine;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Services\IssueReturnService;
use App\Modules\Wms\Services\ManualProductionReceiptPostingService;
use App\Modules\Wms\Services\ProductionFinishedReceiptDocumentService;
use App\Modules\Wms\Services\ProductionReceiptSourceAllocator;
use App\Modules\Wms\Services\ProductionScrapReceiptService;
use App\Modules\Wms\Services\StockReservationService;
use App\Modules\Wms\Support\ManualProductionReceiptContract;
use App\Modules\Wms\Support\WmsDecimal;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class OrderController extends Controller
{
    public function index(): View
    {
        return view('Production::orders.index');
    }

    public function shopFloorIssues(Request $request): View
    {
        return view('Production::shop-floor.issues');
    }

    public function shopFloorIssuesData(Request $request)
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:OPEN,RESOLVED'],
            'severity' => ['nullable', 'in:LOW,MEDIUM,HIGH'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $query = ProductionOrderIssue::query()->with(['order:id,document_number', 'reporter:id,name', 'resolver:id,name'])
            ->where('branch_id', $this->branchId($request))->where('warehouse_id', $warehouseId)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['severity'] ?? null, fn ($query, $severity) => $query->where('severity', $severity))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('reported_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('reported_at', '<=', $date));

        $query->orderByDesc('reported_at')->orderByDesc('id');
        $formatDuration = static function ($from, $to): string {
            if (!$from || !$to) return '-';
            $minutes = max(0, $from->diffInMinutes($to));
            $days = intdiv($minutes, 1440);
            $hours = intdiv($minutes % 1440, 60);
            $remaining = $minutes % 60;
            return collect([$days ? $days.' วัน' : null, $hours ? $hours.' ชม.' : null, $remaining ? $remaining.' นาที' : null])->filter()->join(' ') ?: 'น้อยกว่า 1 นาที';
        };

        return DataTables::eloquent($query)
            ->addColumn('wo_label', fn (ProductionOrderIssue $issue) => $issue->order?->document_number ?: '-')
            ->addColumn('reporter_label', fn (ProductionOrderIssue $issue) => $issue->reporter?->name ?: '-')
            ->addColumn('resolver_label', fn (ProductionOrderIssue $issue) => $issue->resolver?->name ?: '-')
            ->addColumn('resolution_method_label', fn (ProductionOrderIssue $issue) => $issue->resolution_method ?: '-')
            ->addColumn('resolved_at_label', fn (ProductionOrderIssue $issue) => $issue->resolved_at?->format('d/m/Y H:i') ?: '-')
            ->addColumn('resolution_duration_label', fn (ProductionOrderIssue $issue) => $formatDuration($issue->reported_at, $issue->resolved_at))
            ->addColumn('severity_label', fn (ProductionOrderIssue $issue) => ['LOW' => 'ต่ำ', 'MEDIUM' => 'กลาง', 'HIGH' => 'สูง'][$issue->severity] ?? $issue->severity)
            ->addColumn('reported_at_label', fn (ProductionOrderIssue $issue) => $issue->reported_at?->format('d/m/Y H:i') ?: '-')
            ->addColumn('status_label', fn (ProductionOrderIssue $issue) => $issue->status === 'OPEN' ? 'รอตรวจสอบ' : 'แก้ไขแล้ว')
            ->addColumn('resolve_url', fn (ProductionOrderIssue $issue) => $issue->status === 'OPEN' ? route('production.shop-floor.issues.resolve', $issue) : null)
            ->addColumn('show_url', fn (ProductionOrderIssue $issue) => route('production.shop-floor.show', $issue->production_order_id))
            ->filterColumn('wo_label', fn ($query, $keyword) => $query->whereHas('order', fn ($order) => $order->where('document_number', 'like', "%{$keyword}%")))
            ->filterColumn('reporter_label', fn ($query, $keyword) => $query->whereHas('reporter', fn ($user) => $user->where('name', 'like', "%{$keyword}%")))
            ->filterColumn('severity', fn ($query, $keyword) => $query->where('severity', 'like', "%{$keyword}%")->orWhere(fn ($q) => $q->where('severity', $keyword === 'ต่ำ' ? 'LOW' : ($keyword === 'กลาง' ? 'MEDIUM' : ($keyword === 'สูง' ? 'HIGH' : '__NONE__')))))
            ->filterColumn('status', fn ($query, $keyword) => $query->where('status', 'like', "%{$keyword}%")->orWhere(fn ($q) => $q->where('status', $keyword === 'รอตรวจสอบ' ? 'OPEN' : ($keyword === 'แก้ไขแล้ว' ? 'RESOLVED' : '__NONE__'))))
            ->toJson();
    }

    public function reportShopFloorIssue(Request $request, ProductionOrder $order): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request) && (int) $order->issue_warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
        abort_unless($order->status === 'IN_PROGRESS', 422);
        $values = $request->validate(['severity' => ['required', 'in:LOW,MEDIUM,HIGH'], 'description' => ['required', 'string', 'min:10', 'max:2000']]);
        $issue = ProductionOrderIssue::create(['production_order_id' => $order->id, 'branch_id' => $order->branch_id, 'warehouse_id' => $order->issue_warehouse_id, 'reported_by' => $request->user()->id, 'severity' => $values['severity'], 'description' => $values['description'], 'status' => 'OPEN', 'reported_at' => now()]);
        $order->events()->create(['event_type' => 'production_issue_reported', 'source_type' => ProductionOrderIssue::class, 'source_id' => (string) $issue->id, 'payload' => ['severity' => $issue->severity, 'description' => $issue->description], 'occurred_at' => now(), 'created_by' => $request->user()->id]);
        User::query()->where('is_active', true)->whereHas('roles.permissions', fn ($query) => $query->where('code', 'production.orders.view'))
            ->where(fn ($query) => $query->where('primary_branch_id', $order->branch_id)->orWhereHas('branches', fn ($branch) => $branch->whereKey($order->branch_id)))
            ->whereHas('warehouses', fn ($warehouse) => $warehouse->whereKey($order->issue_warehouse_id))
            ->each(fn (User $supervisor) => $supervisor->notify(new ProductionIssueReportedNotification($order->document_number, $issue->description, route('production.shop-floor.show', $order))));

        return response()->json(['status' => true, 'msg' => 'แจ้งปัญหาการผลิตให้ Supervisor แล้ว', 'redirect' => route('production.shop-floor.show', $order)]);
    }

    public function resolveShopFloorIssue(Request $request, ProductionOrderIssue $issue): JsonResponse
    {
        abort_unless((int) $issue->branch_id === $this->branchId($request) && (int) $issue->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
        abort_unless($issue->status === 'OPEN', 422);
        $values = $request->validate(['resolution_method' => ['required', 'string', 'max:2000']]);
        $issue->update(['status' => 'RESOLVED', 'resolved_by' => $request->user()->id, 'resolved_at' => now(), 'resolution_method' => trim($values['resolution_method'])]);
        $issue->order->events()->create(['event_type' => 'production_issue_resolved', 'source_type' => ProductionOrderIssue::class, 'source_id' => (string) $issue->id, 'occurred_at' => now(), 'created_by' => $request->user()->id]);

        return response()->json(['status' => true, 'msg' => 'ปิดปัญหาการผลิตแล้ว', 'redirect' => route('production.shop-floor.issues')]);
    }

    public function shopFloor(Request $request): View
    {
        $filters = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $search = trim((string) ($filters['q'] ?? ''));
        $orders = ProductionOrder::query()
            ->with(['finishedItem:id,code,name', 'uom:id,code', 'salesOrder:id,document_number,party_name', 'events:id,production_order_id,event_type,source_id'])
            ->where('branch_id', $this->branchId($request))
            ->where('issue_warehouse_id', $warehouseId)
            ->whereIn('status', ['RELEASED', 'IN_PROGRESS'])
            ->when($search !== '', fn ($query) => $query->where(fn ($nested) => $nested
                ->where('document_number', 'like', "%{$search}%")
                ->orWhereHas('finishedItem', fn ($item) => $item->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"))
                ->orWhereHas('salesOrder', fn ($salesOrder) => $salesOrder->where('document_number', 'like', "%{$search}%"))))
            ->orderByRaw("FIELD(status, 'IN_PROGRESS', 'RELEASED')")
            ->orderBy('planned_finish_date')->orderBy('id')->limit(50)->get();
        $issueIds = $orders->flatMap(fn (ProductionOrder $order) => $order->events->where('event_type', 'material_issue_created')->pluck('source_id'))->filter()->unique()->values();
        $issueStatuses = $issueIds->isEmpty() ? collect() : IssueDocument::query()->whereIn('id', $issueIds)->pluck('status', 'id');
        $returnDocuments = $issueIds->isEmpty() ? collect() : IssueReturn::query()->whereIn('issue_document_id', $issueIds)->latest('id')->get(['id', 'issue_document_id', 'status'])->groupBy('issue_document_id');
        $scrapDocuments = $issueIds->isEmpty() ? collect() : InventoryAdjustmentDocument::query()->whereIn('source_issue_id', $issueIds)->where('document_context', 'PRODUCTION_SCRAP_RECEIPT')->latest('id')->get(['id', 'source_issue_id', 'status'])->groupBy('source_issue_id');
        $receiptDocuments = $issueIds->isEmpty() ? collect() : InventoryAdjustmentDocument::query()->whereIn('source_issue_id', $issueIds)->where('document_context', ManualProductionReceiptContract::CONTEXT)->latest('id')->get(['id', 'source_issue_id', 'status'])->groupBy('source_issue_id');

        return view('Production::shop-floor.index', compact('orders', 'search', 'issueStatuses', 'returnDocuments', 'scrapDocuments', 'receiptDocuments'));
    }

    public function shopFloorShow(Request $request, ProductionOrder $order, ProductionOrderService $service): View
    {
        abort_unless((int) $order->branch_id === $this->branchId($request) && (int) $order->issue_warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
        $order->load(['finishedItem:id,code,name,cover_image_disk,cover_image_path', 'uom:id,code', 'materials.item:id,code,name,cover_image_disk,cover_image_path', 'materials.uom:id,code', 'operations', 'events:id,production_order_id,event_type,source_id,occurred_at']);
        $issueId = $order->events->firstWhere('event_type', 'material_issue_created')?->source_id;
        $issue = $issueId ? IssueDocument::query()->where('issue_type', 'PRODUCTION')->find($issueId) : null;
        $returnDocument = $issue ? IssueReturn::query()->where('issue_document_id', $issue->id)->latest('id')->first(['id', 'status']) : null;
        $scrapDocument = $issue ? InventoryAdjustmentDocument::query()->where('source_issue_id', $issue->id)->where('document_context', 'PRODUCTION_SCRAP_RECEIPT')->latest('id')->first(['id', 'status']) : null;
        $receiptDocument = $issue ? InventoryAdjustmentDocument::query()->where('source_issue_id', $issue->id)->where('document_context', ManualProductionReceiptContract::CONTEXT)->latest('id')->first(['id', 'status']) : null;
        $productionIssues = ProductionOrderIssue::query()->with(['reporter:id,name', 'resolver:id,name'])->where('production_order_id', $order->id)->latest('reported_at')->get();

        return view('Production::shop-floor.show', compact('order', 'issue', 'returnDocument', 'scrapDocument', 'receiptDocument', 'productionIssues'));
    }

    public function shopFloorItemImage(Request $request, ProductionOrder $order, Item $item, FileStorageService $storage): StreamedResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request) && (int) $order->issue_warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
        abort_unless((int) $item->id === (int) $order->finished_item_id || $order->materials()->where('item_id', $item->id)->exists(), 404);
        abort_unless($item->cover_image_disk && $item->cover_image_path, 404);
        $filesystem = Storage::disk($item->cover_image_disk);
        abort_unless($filesystem->exists($item->cover_image_path), 404);

        return $storage->inline($item->cover_image_disk, $item->cover_image_path, 'production-item-'.$item->id, $filesystem->mimeType($item->cover_image_path) ?: 'image/png');
    }

    public function materialMovements(): View
    {
        return view('Production::orders.material-movements');
    }

    public function materialMovementsData(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', 'in:DRAFT,APPROVED,POSTED,VOID,REVERSED'], 'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d']]);
        $warehouseId = $request->attributes->get('selectedWarehouse')->id;
        $issue = DB::table('wms_issue_documents as d')->join('wms_issue_lines as l', 'l.document_id', '=', 'd.id')->join('wms_items as i', 'i.id', '=', 'l.item_id')->join('wms_uoms as u', 'u.id', '=', 'l.uom_id')->where('d.warehouse_id', $warehouseId)->where('d.issue_type', 'PRODUCTION')->selectRaw("d.id, d.document_number, d.document_date, d.status, d.reason, 'ISSUE' as movement_type, CONCAT(i.code, ' · ', i.name) as item_label, u.code as uom_label, l.quantity, NULL as value");
        $returns = DB::table('wms_issue_returns as d')->join('wms_issue_return_lines as l', 'l.return_id', '=', 'd.id')->join('wms_issue_lines as il', 'il.id', '=', 'l.issue_line_id')->join('wms_items as i', 'i.id', '=', 'il.item_id')->join('wms_uoms as u', 'u.id', '=', 'il.uom_id')->where('d.warehouse_id', $warehouseId)->selectRaw("d.id, d.document_number, d.document_date, d.status, d.reason, 'RETURN' as movement_type, CONCAT(i.code, ' · ', i.name) as item_label, u.code as uom_label, l.quantity, NULL as value");
        $adjustment = DB::table('wms_inventory_adjustment_documents as d')->join('wms_inventory_adjustments as l', 'l.document_id', '=', 'd.id')->join('wms_items as i', 'i.id', '=', 'l.item_id')->join('wms_uoms as u', 'u.id', '=', 'l.uom_id')->where('d.warehouse_id', $warehouseId)->whereIn('d.document_context', ['PRODUCTION_SCRAP_RECEIPT', 'PRODUCTION_FINISHED_RECEIPT'])->selectRaw("d.id, d.document_number, d.document_date, d.status, d.reason, d.document_context as movement_type, CONCAT(i.code, ' · ', i.name) as item_label, u.code as uom_label, l.quantity, l.value");
        $query = DB::query()->fromSub($issue->unionAll($returns)->unionAll($adjustment), 'movements')->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('document_date', '>=', $date))->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('document_date', '<=', $date));
        return DataTables::query($query)->editColumn('movement_type', fn ($row) => ['ISSUE' => 'เบิกวัตถุดิบ', 'RETURN' => 'รับคืนวัตถุดิบ', 'PRODUCTION_SCRAP_RECEIPT' => 'รับ Scrap', 'PRODUCTION_FINISHED_RECEIPT' => 'รับผลิตเสร็จ'][$row->movement_type] ?? $row->movement_type)->addColumn('show_url', fn ($row) => $row->movement_type === 'ISSUE' ? route('wms.production.material-issues.show', $row->id) : ($row->movement_type === 'RETURN' ? route('wms.production.issue-returns.show', $row->id) : route('wms.production.finished-receipts.show', $row->id)))->toJson();
    }

    public function reports(): View
    {
        return view('Production::orders.reports');
    }

    public function costReports(): View
    {
        return view('Production::orders.cost-reports');
    }

    public function costReportsData(Request $request, ProductionOrderService $service)
    {
        $filters = $request->validate(['status' => ['nullable', 'in:DRAFT,RELEASED,IN_PROGRESS,COMPLETED,CANCELLED'], 'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from']]);
        $query = ProductionOrder::query()->with(['events', 'finishedItem:id,code,name', 'uom:id,code'])
            ->where('branch_id', $this->branchId($request))->where('issue_warehouse_id', $request->attributes->get('selectedWarehouse')->id)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))->orderByDesc('created_at');
        $summaryCache = [];
        $summary = function (ProductionOrder $order) use ($service, &$summaryCache): array {
            if (isset($summaryCache[$order->id])) return $summaryCache[$order->id];
            $issueId = $order->events->firstWhere('event_type', 'material_issue_created')?->source_id;
            return $summaryCache[$order->id] = $service->wipSummary($issueId ? IssueDocument::query()->whereKey($issueId)->first() : null);
        };
        return DataTables::eloquent($query)
            ->addColumn('item_label', fn (ProductionOrder $order) => trim(($order->finishedItem?->code ?? '').' · '.($order->finishedItem?->name ?? ''), ' ·'))
            ->addColumn('issued', fn (ProductionOrder $order) => $summary($order)['issued'])
            ->addColumn('returned', fn (ProductionOrder $order) => $summary($order)['returned'])
            ->addColumn('scrap', fn (ProductionOrder $order) => $summary($order)['scrap'])
            ->addColumn('finished', fn (ProductionOrder $order) => $summary($order)['finished'])
            ->addColumn('available', fn (ProductionOrder $order) => $summary($order)['available'])
            ->addColumn('planned_quantity', fn (ProductionOrder $order) => $order->planned_quantity)
            ->addColumn('unit_cost', fn (ProductionOrder $order) => (float) $order->planned_quantity > 0 ? ((float) $summary($order)['finished'] / (float) $order->planned_quantity) : null)
            ->addColumn('show_url', fn (ProductionOrder $order) => route('production.orders.show', $order))
            ->filterColumn('item_label', fn ($q, $keyword) => $q->whereHas('finishedItem', fn ($item) => $item->where('code', 'like', "%{$keyword}%")->orWhere('name', 'like', "%{$keyword}%")))
            ->toJson();
    }

    public function reportsData(Request $request)
    {
        $filters = $request->validate(['status' => ['nullable', 'in:DRAFT,RELEASED,IN_PROGRESS,COMPLETED,CANCELLED'], 'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from']]);
        $query = ProductionOrder::query()->with(['finishedItem:id,code,name', 'uom:id,code', 'responsibleUser:id,name'])
            ->where('branch_id', $this->branchId($request))->where('issue_warehouse_id', $request->attributes->get('selectedWarehouse')->id)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->orderByDesc('created_at');
        return DataTables::eloquent($query)
            ->addColumn('item_label', fn (ProductionOrder $order) => trim(($order->finishedItem?->code ?? '').' · '.($order->finishedItem?->name ?? ''), ' ·'))
            ->addColumn('quantity_label', fn (ProductionOrder $order) => WmsDecimal::format($order->planned_quantity).' '.($order->uom?->code ?? ''))
            ->addColumn('planned_finish_label', fn (ProductionOrder $order) => $order->planned_finish_date?->format('d/m/Y') ?: '-')
            ->addColumn('duration_label', function (ProductionOrder $order): string { $start = $order->started_at ?: $order->released_at; $end = $order->completed_at ?: ($order->status === 'IN_PROGRESS' ? now() : null); return $start && $end ? $start->diffForHumans($end, true) : '-'; })
            ->addColumn('status_label', fn (ProductionOrder $order) => ['DRAFT' => 'ร่าง', 'RELEASED' => 'พร้อมผลิต', 'IN_PROGRESS' => 'กำลังผลิต', 'COMPLETED' => 'เสร็จแล้ว', 'CANCELLED' => 'ยกเลิกเอกสาร'][$order->status] ?? $order->status)
            ->addColumn('show_url', fn (ProductionOrder $order) => route('production.orders.show', $order))
            ->filterColumn('item_label', fn ($q, $keyword) => $q->whereHas('finishedItem', fn ($item) => $item->where('code', 'like', "%{$keyword}%")->orWhere('name', 'like', "%{$keyword}%")))
            ->toJson();
    }

    public function planningOptions(Request $request, string $type)
    {
        abort_unless(in_array($type, ['users', 'products'], true), 404);
        $page = max(1, (int) $request->integer('page', 1));
        $term = trim((string) $request->input('q', ''));
        $query = $type === 'users'
            ? User::query()->where('is_active', true)->select(['id', 'name'])->orderBy('name')
            : Item::query()->whereIn('id', ProductionOrder::query()->select('finished_item_id')->where('branch_id', $this->branchId($request))->where('issue_warehouse_id', $request->attributes->get('selectedWarehouse')->id))->select(['id', 'code', 'name'])->orderBy('code');
        if ($term !== '') $query->where(fn ($q) => $q->where('name', 'like', "%{$term}%")->when($type === 'products', fn ($nested) => $nested->orWhere('code', 'like', "%{$term}%")));
        $rows = $query->forPage($page, 20)->get();
        return response()->json(['results' => $rows->map(fn ($row) => ['id' => $row->id, 'text' => $type === 'users' ? $row->name : $row->code.' · '.$row->name])->values(), 'pagination' => ['more' => $rows->count() === 20]]);
    }

    public function planning(Request $request, ProductionOrderService $service): View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'in:DRAFT,RELEASED,IN_PROGRESS,COMPLETED,CANCELLED'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'responsible_user_id' => ['nullable', 'integer', 'exists:users,id'],
            'finished_item_id' => ['nullable', 'integer', 'exists:wms_items,id'],
            'overdue' => ['nullable', 'boolean'],
            'shortage' => ['nullable', 'boolean'],
        ]);
        $from = $filters['date_from'] ?? today()->startOfMonth()->toDateString();
        $to = $filters['date_to'] ?? today()->endOfMonth()->toDateString();
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $orders = ProductionOrder::query()
            ->with(['finishedItem:id,code,name', 'uom:id,code', 'salesOrder:id,document_number,party_name', 'responsibleUser:id,name'])
            ->where('branch_id', $this->branchId($request))
            ->where('issue_warehouse_id', $warehouseId)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['responsible_user_id'] ?? null, fn ($query, $userId) => $query->where('responsible_user_id', $userId))
            ->when($filters['finished_item_id'] ?? null, fn ($query, $itemId) => $query->where('finished_item_id', $itemId))
            ->when($filters['overdue'] ?? false, fn ($query) => $query->whereNotNull('planned_finish_date')->whereDate('planned_finish_date', '<', today())->whereNotIn('status', ['COMPLETED', 'CANCELLED']))
            ->where(function ($query) use ($from, $to): void {
                $query->where(function ($range) use ($from, $to): void {
                    $range->whereNotNull('planned_start_date')->whereDate('planned_start_date', '<=', $to)
                        ->where(function ($finish) use ($from): void {
                            $finish->whereNull('planned_finish_date')->orWhereDate('planned_finish_date', '>=', $from);
                        });
                })->orWhere(function ($undated) use ($from, $to): void {
                    $undated->whereNull('planned_start_date')->whereDate('created_at', '<=', $to)->whereDate('created_at', '>=', $from);
                });
            })
            ->orderByRaw('planned_start_date IS NULL')->orderBy('planned_start_date')->orderBy('id')
            ->limit(200)->get();
        if ($filters['shortage'] ?? false) {
            $orders = $orders->filter(function (ProductionOrder $order) use ($service): bool {
                $order->planning_readiness = $service->materialReadiness($order);
                return ! $order->planning_readiness['ready'];
            })->values();
        }

        return view('Production::orders.planning', [
            'orders' => $orders, 'filters' => [...$filters, 'date_from' => $from, 'date_to' => $to],
            'selectedResponsible' => ($filters['responsible_user_id'] ?? null) ? User::query()->find($filters['responsible_user_id']) : null,
            'products' => Item::query()->whereIn('id', $orders->pluck('finished_item_id'))->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function create(Request $request): View
    {
        $revisions = BomRevision::query()->with(['bom.finishedItem:id,code,name', 'bom.baseUom:id,code,name', 'lines.componentItem:id,code,name', 'lines.uom:id,code,name', 'lines.substitutes.substituteItem:id,code,name'])
            ->where('status', 'ACTIVE')
            ->whereHas('bom', fn ($q) => $q->where('branch_id', $this->branchId($request))->where('is_active', true))
            ->orderByDesc('id')
            ->get();

        return view('Production::orders.form', ['order' => new ProductionOrder(['planned_start_date' => now()]), 'revisions' => $revisions]);
    }

    public function store(Request $request, ProductionOrderService $service): JsonResponse
    {
        $values = $request->validate([
            'bom_revision_id' => ['required', 'integer', 'exists:production_bom_revisions,id'],
            'planned_quantity' => ['required', 'numeric', 'gt:0'],
            'planned_start_date' => ['nullable', 'date_format:Y-m-d'],
            'planned_finish_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:planned_start_date'],
            'planned_start_at' => ['nullable', 'date_format:Y-m-d\\TH:i'],
            'planned_finish_at' => ['nullable', 'date_format:Y-m-d\\TH:i', 'after_or_equal:planned_start_at'],
            'required_delivery_at' => ['nullable', 'date_format:Y-m-d\\TH:i'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'substitutes' => ['nullable', 'array', 'max:100'],
            'substitutes.*' => ['nullable', 'integer', 'exists:production_bom_line_substitutes,id'],
        ]);
        $values['substitutes'] = array_filter($values['substitutes'] ?? [], fn ($value) => filled($value));
        abort_unless($values['substitutes'] === [] || $request->user()->hasPermission('production.orders.substitute.use'), 403);
        $order = $service->createMakeToStock($values, $request->attributes->get('selectedWarehouse'), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'สร้างใบสั่งผลิต Make to Stock แล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function edit(Request $request, ProductionOrder $order): View
    {
        abort_unless((int) $order->branch_id === $this->branchId($request) && $order->status === 'DRAFT' && $order->order_type === 'MAKE_TO_STOCK', 404);
        $revisions = BomRevision::query()->with(['bom.finishedItem:id,code,name', 'bom.baseUom:id,code,name', 'lines.componentItem:id,code,name', 'lines.uom:id,code,name', 'lines.substitutes.substituteItem:id,code,name'])
            ->where('status', 'ACTIVE')
            ->whereHas('bom', fn ($q) => $q->where('branch_id', $this->branchId($request))->where('is_active', true))
            ->orderByDesc('id')
            ->get();

        return view('Production::orders.form', ['order' => $order, 'revisions' => $revisions]);
    }

    public function update(Request $request, ProductionOrder $order, ProductionOrderService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $values = $request->validate([
            'bom_revision_id' => ['required', 'integer', 'exists:production_bom_revisions,id'],
            'planned_quantity' => ['required', 'numeric', 'gt:0'],
            'planned_start_date' => ['nullable', 'date_format:Y-m-d'],
            'planned_finish_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:planned_start_date'],
            'planned_start_at' => ['nullable', 'date_format:Y-m-d\\TH:i'],
            'planned_finish_at' => ['nullable', 'date_format:Y-m-d\\TH:i', 'after_or_equal:planned_start_at'],
            'required_delivery_at' => ['nullable', 'date_format:Y-m-d\\TH:i'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'substitutes' => ['nullable', 'array', 'max:100'],
            'substitutes.*' => ['nullable', 'integer', 'exists:production_bom_line_substitutes,id'],
        ]);
        $values['substitutes'] = array_filter($values['substitutes'] ?? [], fn ($value) => filled($value));
        abort_unless($values['substitutes'] === [] || $request->user()->hasPermission('production.orders.substitute.use'), 403);
        $updated = $service->updateDraft($order, $values, $request->attributes->get('selectedWarehouse'), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'บันทึกใบสั่งผลิตแล้ว', 'redirect' => route('production.orders.show', $updated)]);
    }

    public function destroy(Request $request, ProductionOrder $order, ProductionOrderService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $service->deleteDraft($order, $request->attributes->get('selectedWarehouse'), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ลบร่างใบสั่งผลิตแล้ว', 'redirect' => route('production.orders.index')]);
    }

    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate(['status' => ['nullable', 'in:DRAFT,RELEASED,IN_PROGRESS,COMPLETED,CANCELLED'], 'date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from']]);
        $query = ProductionOrder::query()
            ->with(['salesOrder:id,document_number,party_code,party_name', 'finishedItem:id,code,name', 'uom:id,code'])
            ->where('branch_id', $this->branchId($request))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date))
            ->latest('created_at')->latest('id');

        return DataTables::eloquent($query)
            ->addColumn('source_label', fn (ProductionOrder $row) => $row->salesOrder ? $row->salesOrder->document_number.' · '.$row->salesOrder->party_name : 'Make to Stock')
            ->addColumn('finished_item_label', fn (ProductionOrder $row) => trim(($row->finishedItem?->code ?? '').' · '.($row->finishedItem?->name ?? ''), ' ·'))
            ->addColumn('quantity_label', fn (ProductionOrder $row) => WmsDecimal::format($row->planned_quantity).' '.($row->uom?->code ?? ''))
            ->addColumn('status_label', fn (ProductionOrder $row) => ['DRAFT' => 'ร่าง', 'RELEASED' => 'Release แล้ว', 'IN_PROGRESS' => 'กำลังผลิต', 'COMPLETED' => 'เสร็จสิ้น', 'CANCELLED' => 'ยกเลิก'][$row->status] ?? $row->status)
            ->addColumn('show_url', fn (ProductionOrder $row) => route('production.orders.show', $row))
            ->addColumn('edit_url', fn (ProductionOrder $row) => $row->status === 'DRAFT' && $row->order_type === 'MAKE_TO_STOCK' && $request->user()->hasPermission('production.orders.update') ? route('production.orders.edit', $row) : null)
            ->toJson();
    }

    public function demand(): View
    {
        return view('Production::orders.demand');
    }

    public function demandData(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);
        $query = SalesOrderLine::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->leftJoin('wms_items', 'wms_items.id', '=', 'sales_order_lines.item_id')
            ->leftJoin('wms_uoms', 'wms_uoms.id', '=', 'sales_order_lines.uom_id')
            ->where('sales_orders.branch_id', $branchId)
            ->where('sales_orders.status', 'CONFIRMED')
            ->whereExists(fn ($exists) => $exists->selectRaw('1')->from('production_boms')
                ->join('production_bom_revisions', 'production_bom_revisions.bom_id', '=', 'production_boms.id')
                ->whereColumn('production_boms.finished_item_id', 'sales_order_lines.item_id')
                ->whereColumn('production_boms.base_uom_id', 'sales_order_lines.uom_id')
                ->where('production_boms.branch_id', $branchId)
                ->where('production_boms.is_active', true)
                ->where('production_bom_revisions.status', 'ACTIVE'))
            ->whereNotExists(fn ($exists) => $exists->selectRaw('1')->from('production_orders')
                ->whereColumn('production_orders.sales_order_line_id', 'sales_order_lines.id')
                ->where('production_orders.status', '!=', 'CANCELLED')
                ->whereNull('production_orders.deleted_at'))
            ->select([
                'sales_order_lines.*', 'sales_orders.document_number as sales_order_number', 'sales_orders.party_code', 'sales_orders.party_name', 'sales_orders.required_delivery_date',
                'wms_items.code as item_code', 'wms_items.name as item_name', 'wms_uoms.code as uom_code',
            ]);

        return DataTables::eloquent($query)
            ->addColumn('sales_order_label', fn ($row) => $row->sales_order_number.' · '.$row->party_name)
            ->addColumn('item_label', fn ($row) => trim(($row->item_code ?? '').' · '.($row->item_name ?? ''), ' ·'))
            ->addColumn('quantity_label', fn ($row) => WmsDecimal::format($row->quantity).' '.($row->uom_code ?? ''))
            ->addColumn('required_delivery_date_label', fn ($row) => $row->required_delivery_date ? date('d/m/Y', strtotime((string) $row->required_delivery_date)) : '-')
            ->addColumn('create_url', fn ($row) => $request->user()->hasPermission('production.orders.create') ? route('production.orders.store-from-demand', $row->id) : null)
            ->toJson();
    }

    public function storeFromDemand(Request $request, int $line, ProductionOrderService $service): JsonResponse
    {
        $order = $service->createFromSalesOrderLine($line, $request->attributes->get('selectedWarehouse'), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'สร้างใบสั่งผลิตแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function show(Request $request, ProductionOrder $order, ProductionOrderService $service, ManualProductionReceiptPostingService $finishedReceiptPosting): View
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $order->load(['salesOrder', 'salesOrderLine', 'finishedItem', 'uom', 'bomRevision.bom', 'materials.item', 'materials.uom', 'scraps.uom', 'events.creator']);
        $materialReadiness = $service->materialReadiness($order);
        $materialIssueId = $order->events->firstWhere('event_type', 'material_issue_created')?->source_id;
        $materialIssue = $materialIssueId ? IssueDocument::query()->where('issue_type', 'PRODUCTION')->find($materialIssueId) : null;
        $materialReturns = $materialIssue ? IssueReturn::query()->where('issue_document_id', $materialIssue->id)->latest('id')->get(['id', 'document_number', 'status']) : collect();
        $scrapReceipts = $materialIssue ? InventoryAdjustmentDocument::query()->where('source_issue_id', $materialIssue->id)->where('document_context', 'PRODUCTION_SCRAP_RECEIPT')->latest('id')->get(['id', 'document_number', 'status']) : collect();
        $finishedReceipts = $materialIssue ? InventoryAdjustmentDocument::query()->with('lines')->where('source_issue_id', $materialIssue->id)->where('document_context', ManualProductionReceiptContract::CONTEXT)->latest('id')->get(['id', 'warehouse_id', 'document_number', 'document_date', 'status', 'document_context', 'source_issue_id', 'reason']) : collect();
        $finishedReceiptReadiness = $finishedReceipts->where('status', 'APPROVED')->mapWithKeys(function (InventoryAdjustmentDocument $receipt) use ($finishedReceiptPosting): array {
            try { return [$receipt->id => $finishedReceiptPosting->preflight($receipt->toArray())]; }
            catch (\Throwable $e) { return [$receipt->id => ['ready' => false, 'blockers' => [['message' => $e->getMessage()]]]]; }
        });
        $journalIds = $request->user()->hasPermission('production.orders.gl.view') ? $this->productionJournalIds($order) : collect();
        $journalPreviewUrls = $journalIds->map(fn (int $id) => route('production.orders.journal-preview', [$order, $id]))->values();
        $journalProofRows = $journalIds->isEmpty() ? collect() : JournalEntry::query()->whereIn('id', $journalIds)->orderBy('entry_date')->orderBy('id')->get(['id', 'entry_number', 'entry_date', 'source_type', 'source_id', 'status']);
        $wipSummary = $service->wipSummary($materialIssue);
        $canViewFinishedReceipt = $request->user()->hasPermission('wms.inventory-adjustments.view');

        return view('Production::orders.show', compact('order', 'materialReadiness', 'materialIssue', 'materialReturns', 'scrapReceipts', 'finishedReceipts', 'finishedReceiptReadiness', 'journalPreviewUrls', 'journalProofRows', 'wipSummary', 'canViewFinishedReceipt'));
    }

    public function journalPreview(Request $request, ProductionOrder $order, JournalEntry $journalEntry): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        abort_unless($this->productionJournalIds($order)->contains((int) $journalEntry->id), 404);
        $journalEntry->load(['book:id,code,name', 'period.fiscalYear:id,name', 'lines.account:id,code,name', 'lines.taxCode:id,code']);

        return response()->json([
            'entry_number' => $journalEntry->entry_number,
            'status' => $journalEntry->status,
            'status_label' => ['DRAFT' => 'ร่าง', 'VALIDATED' => 'รออนุมัติ', 'POSTED' => 'ลงบัญชีแล้ว', 'REVERSED' => 'กลับรายการแล้ว'][$journalEntry->status] ?? $journalEntry->status,
            'entry_date' => $journalEntry->entry_date?->format('d/m/Y'),
            'document_date' => $journalEntry->document_date?->format('d/m/Y') ?: '—',
            'book' => $journalEntry->book?->code.' · '.$journalEntry->book?->name,
            'period' => $journalEntry->period?->fiscalYear?->name.' / '.$journalEntry->period?->period_number,
            'description' => $journalEntry->description,
            'lines' => $journalEntry->lines->map(fn ($line) => [
                'line_number' => $line->line_number,
                'account' => $line->account?->code.' · '.$line->account?->name,
                'description' => $line->description ?: '—',
                'tax_code' => $line->taxCode?->code,
                'debit' => (string) $line->debit,
                'credit' => (string) $line->credit,
            ])->values(),
        ]);
    }

    public function release(Request $request, ProductionOrder $order, ProductionOrderService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $released = $service->release($order, $request->attributes->get('selectedWarehouse'), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'Release ใบสั่งผลิตแล้ว', 'redirect' => route('production.orders.show', $released)]);
    }

    public function deleteRecoverableScrapReceipt(Request $request, ProductionOrder $order, InventoryAdjustmentDocument $document, ProductionScrapReceiptService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request)
            && (int) $document->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id
            && (int) $document->source_issue_id > 0, 404);
        $service->deleteDraft($document, $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ลบร่างใบรับเศษผลิตแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function approveRecoverableScrapReceipt(Request $request, ProductionOrder $order, InventoryAdjustmentDocument $document, ProductionScrapReceiptService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request) && (int) $document->source_issue_id > 0, 404);
        $service->approve($document, $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'อนุมัติใบรับเศษผลิตแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function postRecoverableScrapReceipt(Request $request, ProductionOrder $order, InventoryAdjustmentDocument $document, ProductionScrapReceiptService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request) && (int) $document->source_issue_id > 0, 404);
        $service->post($document, $request->attributes->get('selectedWarehouse'), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ใบรับเศษผลิตลง Stock และบัญชีแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function reverseRecoverableScrapReceipt(Request $request, ProductionOrder $order, InventoryAdjustmentDocument $document, ProductionScrapReceiptService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request) && (int) $document->source_issue_id > 0, 404);
        $values = $request->validate(['reversal_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:10', 'max:500']]);
        $service->reverse($document, (string) $values['reversal_date'], (string) $values['reason'], $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'กลับรายการใบรับเศษผลิตแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function approveMaterialIssue(Request $request, ProductionOrder $order, IssueDocument $document, IssueReturnService $issues, AuditLogger $audit): JsonResponse
    {
        $this->assertMaterialIssueBelongsToOrder($request, $order, $document);
        $issues->approve($document, $request->user(), $audit, $request);

        return response()->json(['status' => true, 'msg' => 'อนุมัติใบเบิกวัตถุดิบแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function postMaterialIssue(Request $request, ProductionOrder $order, IssueDocument $document, IssueReturnService $issues, AuditLogger $audit): JsonResponse
    {
        $this->assertMaterialIssueBelongsToOrder($request, $order, $document);
        $issues->post($document, $request->attributes->get('selectedWarehouse'), $request->user(), $audit, $request);

        return response()->json(['status' => true, 'msg' => 'ใบเบิกวัตถุดิบลง Stock แล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function reverseMaterialIssue(Request $request, ProductionOrder $order, IssueDocument $document, IssueReturnService $issues, AuditLogger $audit): JsonResponse
    {
        $this->assertMaterialIssueBelongsToOrder($request, $order, $document);
        $values = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $issues->reverseIssue($document, $request->user(), (string) $values['reason'], $audit, $request);

        return response()->json(['status' => true, 'msg' => 'ยกเลิกเอกสารใบเบิกวัตถุดิบแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function createMaterialReturn(Request $request, ProductionOrder $order, IssueReturnService $returns, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $values = $request->validate(['reason' => ['nullable', 'string', 'min:10', 'max:500']]);
        $order->load('events');
        $issueId = $order->events->firstWhere('event_type', 'material_issue_created')?->source_id;
        $issue = $issueId ? IssueDocument::query()->where('issue_type', 'PRODUCTION')->where('status', 'POSTED')->find($issueId) : null;
        if (! $issue) throw ValidationException::withMessages(['issue_document_id' => 'ต้องมีใบเบิกวัตถุดิบที่ลง Stock แล้วก่อนรับคืน']);
        $lines = $this->materialReturnLines($issue);
        if ($lines === []) throw ValidationException::withMessages(['lines' => 'ไม่มีวัตถุดิบคงเหลือให้รับคืน']);
        $document = $returns->createReturn([
            'issue_document_id' => $issue->id,
            'idempotency_key' => 'production-order:'.$order->id.':material-return:issue:'.$issue->id,
            'document_date' => Carbon::today()->format('Y-m-d'),
            'reason' => $values['reason'] ?? 'คืนวัตถุดิบจาก WO '.$order->document_number,
            'lines' => $lines,
        ], $request->attributes->get('selectedWarehouse'), $request->user(), $sequences, $audit, $request);

        return response()->json(['status' => true, 'msg' => 'สร้างร่างใบรับคืนวัตถุดิบแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function approveMaterialReturn(Request $request, ProductionOrder $order, IssueReturn $document, IssueReturnService $returns, AuditLogger $audit): JsonResponse
    {
        $this->assertMaterialReturnBelongsToOrder($request, $order, $document);
        $returns->approve($document, $request->user(), $audit, $request);

        return response()->json(['status' => true, 'msg' => 'อนุมัติใบรับคืนวัตถุดิบแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function postMaterialReturn(Request $request, ProductionOrder $order, IssueReturn $document, IssueReturnService $returns, AuditLogger $audit): JsonResponse
    {
        $this->assertMaterialReturnBelongsToOrder($request, $order, $document);
        $returns->postReturn($document, $request->attributes->get('selectedWarehouse'), $request->user(), $audit, $request);

        return response()->json(['status' => true, 'msg' => 'ใบรับคืนวัตถุดิบลง Stock แล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function reverseMaterialReturn(Request $request, ProductionOrder $order, IssueReturn $document, IssueReturnService $returns, AuditLogger $audit): JsonResponse
    {
        $this->assertMaterialReturnBelongsToOrder($request, $order, $document);
        $values = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $returns->reverseReturn($document, $request->user(), (string) $values['reason'], $audit, $request);

        return response()->json(['status' => true, 'msg' => 'ยกเลิกเอกสารใบรับคืนวัตถุดิบแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function createFinishedReceipt(Request $request, ProductionOrder $order, ProductionOrderService $orders, ProductionFinishedReceiptDocumentService $receipts, ProductionReceiptSourceAllocator $allocator): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $values = $request->validate(['quantity' => ['nullable', 'numeric', 'gt:0'], 'reason' => ['nullable', 'string', 'min:10', 'max:500']]);
        $order->load('events');
        $issueId = $order->events->firstWhere('event_type', 'material_issue_created')?->source_id;
        $issue = $issueId ? IssueDocument::query()->where('issue_type', 'PRODUCTION')->where('status', 'POSTED')->find($issueId) : null;
        if (! $issue || $order->status !== 'IN_PROGRESS' || $order->held_at) throw ValidationException::withMessages(['source_issue_id' => $order->held_at ? 'ต้องเปิดงานผลิตต่อก่อนรับผลิตเสร็จ' : 'ต้องมีใบเบิกวัตถุดิบที่ลง Stock แล้วและ WO ต้องกำลังผลิต']);
        if ($this->hasActiveFinishedReceipt($issue->id)) throw ValidationException::withMessages(['finished_receipt' => 'WO นี้มีใบรับผลิตแล้ว ต้องยกเลิก/กลับรายการก่อนสร้างใหม่']);
        $value = BigDecimal::of((string) $orders->wipSummary($issue)['available'])->toScale(8, RoundingMode::HALF_UP);
        if (! $value->isPositive()) throw ValidationException::withMessages(['value' => 'Available WIP ต้องมากกว่า 0']);
        $plannedQuantity = BigDecimal::of((string) $order->planned_quantity)->toScale(8, RoundingMode::HALF_UP);
        $quantity = BigDecimal::of((string) ($values['quantity'] ?? $plannedQuantity->__toString()))->toScale(8, RoundingMode::HALF_UP);
        if (! $quantity->isEqualTo($plannedQuantity)) throw ValidationException::withMessages(['quantity' => '1 WO ต้องรับผลิตครั้งเดียวเท่ากับ Planned quantity']);
        $document = $receipts->create([
            'document_date' => Carbon::today()->format('Y-m-d'),
            'reason' => $values['reason'] ?? 'รับสินค้าผลิตเสร็จจาก WO '.$order->document_number,
            'idempotency_key' => 'production-order:'.$order->id.':finished-receipt:issue:'.$issue->id,
            'line_idempotency_prefix' => 'production-order:'.$order->id.':finished-receipt:issue:'.$issue->id,
            'source_issue_ids' => [$issue->id],
            'source_consumptions' => $allocator->allocate($this->finishedReceiptSourceRows($issue), $value->__toString()),
            'lines' => [['item_id' => $order->finished_item_id, 'uom_id' => $order->uom_id, 'quantity' => $quantity->__toString(), 'value' => $value->__toString()]],
        ], $request->attributes->get('selectedWarehouse'), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'สร้างร่างใบรับสินค้าผลิตเสร็จแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function approveFinishedReceipt(Request $request, ProductionOrder $order, InventoryAdjustmentDocument $document, ProductionFinishedReceiptDocumentService $receipts): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request) && $document->document_context === ManualProductionReceiptContract::CONTEXT, 404);
        $receipts->approve($document, $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'อนุมัติใบรับสินค้าผลิตเสร็จแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function postFinishedReceipt(Request $request, ProductionOrder $order, InventoryAdjustmentDocument $document, ManualProductionReceiptPostingService $posting): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request) && $document->document_context === ManualProductionReceiptContract::CONTEXT, 404);
        $posting->post($document, $request->attributes->get('selectedWarehouse'), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ใบรับสินค้าผลิตเสร็จลง Stock และบัญชีแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function createRecoverableScrapReceipt(Request $request, ProductionOrder $order, ProductionOrderService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $document = $service->createRecoverableScrapReceipt($order, $request->attributes->get('selectedWarehouse'), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'สร้างร่างใบรับเศษผลิตแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function reportNonRecoverableScrap(Request $request, ProductionOrder $order, ProductionOrderService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $service->reportNonRecoverableScrap($order, $request->attributes->get('selectedWarehouse'), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'บันทึกของเสียไม่มีมูลค่าแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    public function cancel(Request $request, ProductionOrder $order, ProductionOrderService $service, StockReservationService $reservations): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $cancelled = $service->cancel($order, $request->attributes->get('selectedWarehouse'), $request->user(), $request, $reservations);

        return response()->json(['status' => true, 'msg' => 'ยกเลิกเอกสาร WO แล้ว', 'redirect' => route('production.orders.show', $cancelled)]);
    }

    public function startFromShopFloor(Request $request, ProductionOrder $order, ProductionOrderService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $started = $service->startFromShopFloor($order, $request->attributes->get('selectedWarehouse'), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ยืนยันเริ่มงานผลิตแล้ว', 'redirect' => route('production.shop-floor.show', $started)]);
    }

    public function addOperation(Request $request, ProductionOrder $order, ProductionOrderService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $values = $request->validate(['name' => ['required', 'string', 'max:150'], 'planned_minutes' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $service->addOperation($order, $request->attributes->get('selectedWarehouse'), $request->user(), $request, $values['name'], $values['planned_minutes'] ?? null);
        return response()->json(['status' => true, 'msg' => 'เพิ่มขั้นตอนการผลิตแล้ว', 'redirect' => route('production.shop-floor.show', $order)]);
    }

    public function updateOperation(Request $request, ProductionOrder $order, ProductionOrderOperation $operation, ProductionOrderService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $values = $request->validate(['name' => ['required', 'string', 'max:150'], 'planned_minutes' => ['nullable', 'integer', 'min:1', 'max:100000']]);
        $service->updateOperation($order, $operation, $request->attributes->get('selectedWarehouse'), $request->user(), $request, $values['name'], $values['planned_minutes'] ?? null);
        return response()->json(['status' => true, 'msg' => 'แก้ไขขั้นตอนการผลิตแล้ว', 'redirect' => route('production.shop-floor.show', $order)]);
    }

    public function deleteOperation(Request $request, ProductionOrder $order, ProductionOrderOperation $operation, ProductionOrderService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $service->deleteOperation($order, $operation, $request->attributes->get('selectedWarehouse'), $request->user(), $request);
        return response()->json(['status' => true, 'msg' => 'ลบขั้นตอนการผลิตแล้ว', 'redirect' => route('production.shop-floor.show', $order)]);
    }

    public function startOperation(Request $request, ProductionOrder $order, ProductionOrderOperation $operation, ProductionOrderService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $service->startOperation($order, $operation, $request->attributes->get('selectedWarehouse'), $request->user(), $request);
        return response()->json(['status' => true, 'msg' => 'เริ่ม Operation แล้ว', 'redirect' => route('production.shop-floor.show', $order)]);
    }

    public function completeOperation(Request $request, ProductionOrder $order, ProductionOrderOperation $operation, ProductionOrderService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $service->completeOperation($order, $operation, $request->attributes->get('selectedWarehouse'), $request->user(), $request);
        return response()->json(['status' => true, 'msg' => 'จบ Operation แล้ว', 'redirect' => route('production.shop-floor.show', $order)]);
    }

    public function hold(Request $request, ProductionOrder $order, ProductionOrderService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $values = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $held = $service->hold($order, $request->attributes->get('selectedWarehouse'), $request->user(), $request, $values['reason']);
        return response()->json(['status' => true, 'msg' => 'พักงานผลิตแล้ว', 'redirect' => route('production.shop-floor.show', $held)]);
    }

    public function resume(Request $request, ProductionOrder $order, ProductionOrderService $service): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $resumed = $service->resume($order, $request->attributes->get('selectedWarehouse'), $request->user(), $request);
        return response()->json(['status' => true, 'msg' => 'เปิดงานผลิตต่อแล้ว', 'redirect' => route('production.shop-floor.show', $resumed)]);
    }

    public function reserveMaterials(Request $request, ProductionOrder $order, ProductionOrderService $service, StockReservationService $reservations): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $reserved = $service->reserveMaterials($order, $request->attributes->get('selectedWarehouse'), $request->user(), $request, $reservations);

        return response()->json(['status' => true, 'msg' => 'จองวัตถุดิบสำหรับ WO แล้ว', 'redirect' => route('production.orders.show', $reserved)]);
    }

    public function createMaterialIssue(Request $request, ProductionOrder $order, ProductionOrderService $service, IssueReturnService $issues): JsonResponse
    {
        abort_unless((int) $order->branch_id === $this->branchId($request), 404);
        $document = $service->createMaterialIssue($order, $request->attributes->get('selectedWarehouse'), $request->user(), $request, $issues);

        return response()->json(['status' => true, 'msg' => 'สร้างร่างใบเบิกวัตถุดิบแล้ว', 'redirect' => route('production.orders.show', $order)]);
    }

    private function assertMaterialReturnBelongsToOrder(Request $request, ProductionOrder $order, IssueReturn $document): void
    {
        abort_unless((int) $order->branch_id === $this->branchId($request)
            && (int) $document->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id
            && $order->events()->where('event_type', 'material_issue_created')->where('source_id', (string) $document->issue_document_id)->exists(), 404);
    }

    private function assertMaterialIssueBelongsToOrder(Request $request, ProductionOrder $order, IssueDocument $document): void
    {
        abort_unless((int) $order->branch_id === $this->branchId($request)
            && (int) $document->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id
            && $document->issue_type === 'PRODUCTION'
            && $order->events()->where('event_type', 'material_issue_created')->where('source_id', (string) $document->id)->exists(), 404);
    }

    private function hasActiveFinishedReceipt(int $sourceIssueId): bool
    {
        return DB::table('wms_inventory_adjustment_documents')
            ->where('document_context', ManualProductionReceiptContract::CONTEXT)
            ->where('source_issue_id', $sourceIssueId)
            ->whereIn('status', ['DRAFT', 'APPROVED', 'POSTED'])
            ->where(fn ($q) => $q->whereNull('reversal_status')->orWhere('reversal_status', '!=', 'REVERSED'))
            ->whereNull('deleted_at')
            ->exists();
    }

    private function productionJournalIds(ProductionOrder $order): Collection
    {
        $order->loadMissing('events');
        $issueIds = $order->events->where('event_type', 'material_issue_created')->pluck('source_id')->filter()->map(fn ($id) => (int) $id)->values();
        $returnIds = $issueIds->isEmpty() ? collect() : IssueReturn::query()->whereIn('issue_document_id', $issueIds)->pluck('id');
        $receiptIds = $issueIds->isEmpty() ? collect() : InventoryAdjustmentDocument::query()->whereIn('source_issue_id', $issueIds)->where('document_context', ManualProductionReceiptContract::CONTEXT)->pluck('id');
        $scrapIds = $issueIds->isEmpty() ? collect() : InventoryAdjustmentDocument::query()->whereIn('source_issue_id', $issueIds)->where('document_context', 'PRODUCTION_SCRAP_RECEIPT')->pluck('id');
        if ($issueIds->isEmpty() && $returnIds->isEmpty() && $receiptIds->isEmpty() && $scrapIds->isEmpty()) return collect();
        $query = JournalEntry::query()->where(function ($q) use ($issueIds, $returnIds, $receiptIds, $scrapIds): void {
            if ($issueIds->isNotEmpty()) $q->orWhere(fn ($x) => $x->where('source_type', 'WMS_ISSUE')->whereIn('source_id', $issueIds->map(fn ($id) => (string) $id)));
            if ($returnIds->isNotEmpty()) $q->orWhere(fn ($x) => $x->where('source_type', 'WMS_ISSUE_RETURN')->where(function ($nested) use ($returnIds): void {
                $nested->whereIn('source_id', $returnIds->map(fn ($id) => (string) $id));
                foreach ($returnIds as $id) $nested->orWhere('source_id', 'like', 'issue-return:'.$id.':reversal:%');
            }));
            if ($receiptIds->isNotEmpty()) $q->orWhere(fn ($x) => $x->where('source_type', 'WMS_PRODUCTION_RECEIPT')->where(function ($nested) use ($receiptIds): void {
                $nested->whereIn('source_id', $receiptIds->map(fn ($id) => (string) $id));
                foreach ($receiptIds as $id) $nested->orWhere('source_id', 'like', 'reversal:production-finished-receipt:'.$id.':%');
            }));
            if ($scrapIds->isNotEmpty()) $q->orWhere(fn ($x) => $x->where('source_type', 'WMS_PRODUCTION_SCRAP_RECEIPT')->where(function ($nested) use ($scrapIds): void {
                $nested->whereIn('source_id', $scrapIds->map(fn ($id) => (string) $id));
                foreach ($scrapIds as $id) $nested->orWhere('source_id', 'like', 'reversal:production-scrap-receipt:'.$id.':%');
            }));
        });

        return $query->orderBy('entry_date')->orderBy('id')->pluck('id');
    }

    private function materialReturnLines(IssueDocument $issue): array
    {
        return IssueLine::query()->where('document_id', $issue->id)->get()->map(function (IssueLine $line): ?array {
            $returned = BigDecimal::of((string) IssueReturnLine::query()
                ->where('issue_line_id', $line->id)
                ->whereHas('return', fn ($q) => $q->whereIn('status', ['APPROVED', 'POSTED']))
                ->sum('quantity'));
            $remaining = BigDecimal::of((string) $line->quantity)->minus($returned)->toScale(8, RoundingMode::HALF_UP);
            return $remaining->isPositive() ? ['issue_line_id' => $line->id, 'quantity' => $remaining->__toString()] : null;
        })->filter()->values()->all();
    }

    private function finishedReceiptSourceRows(IssueDocument $issue): array
    {
        $issue->load(['lines.costAllocations']);
        $allocationIds = $issue->lines->flatMap(fn ($line) => $line->costAllocations)->pluck('id')->all();
        $usedValues = Schema::hasTable('wms_production_receipt_sources')
            ? DB::table('wms_production_receipt_sources')->whereIn('source_allocation_id', $allocationIds)->selectRaw('source_allocation_id, SUM(consumed_value) as consumed_value')->groupBy('source_allocation_id')->pluck('consumed_value', 'source_allocation_id')
            : collect();
        $usedQty = Schema::hasTable('wms_production_receipt_sources')
            ? DB::table('wms_production_receipt_sources')->whereIn('source_allocation_id', $allocationIds)->selectRaw('source_allocation_id, SUM(consumed_quantity) as consumed_quantity')->groupBy('source_allocation_id')->pluck('consumed_quantity', 'source_allocation_id')
            : collect();
        $rows = [];
        foreach ($issue->lines as $line) {
            foreach ($line->costAllocations->where('status', '!=', 'REVERSED')->where('cost_status', 'FINAL') as $allocation) {
                $value = BigDecimal::of((string) $allocation->value)->abs()->minus((string) ($usedValues->get($allocation->id) ?? '0'))->toScale(8, RoundingMode::HALF_UP);
                if (! $value->isPositive()) continue;
                $rows[] = [
                    'issue_document_id' => $issue->id,
                    'issue_line_id' => $line->id,
                    'source_allocation_id' => $allocation->id,
                    'source_allocation_revision' => (int) $allocation->revision,
                    'available_quantity' => BigDecimal::of((string) $allocation->quantity)->abs()->minus((string) ($usedQty->get($allocation->id) ?? '0'))->toScale(8, RoundingMode::HALF_UP)->__toString(),
                    'available_value' => $value->__toString(),
                ];
            }
        }
        if ($rows === []) throw ValidationException::withMessages(['sources' => 'ไม่พบต้นทุน WIP คงเหลือสำหรับรับผลิต']);

        return $rows;
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedWarehouse')->branch_id;
    }
}
