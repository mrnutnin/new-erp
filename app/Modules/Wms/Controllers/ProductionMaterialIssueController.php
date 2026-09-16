<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Requests\SaveIssueDocumentRequest;
use App\Modules\Wms\Services\IssueReturnService;
use App\Modules\Wms\Services\StockBalanceService;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class ProductionMaterialIssueController extends Controller
{
    public function index(): View
    {
        return view('Wms::production.material-issues.index');
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $labels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิกเอกสาร'];
        $relations = ['lines.item:id,code,name', 'issueReturns.lines'];
        if (Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id')) $relations[] = 'finishedReceipts.lines';
        $query = IssueDocument::query()->with($relations)
            ->where('warehouse_id', $warehouseId)->where('issue_type', 'PRODUCTION')->latest('id');
        if ($request->filled('status')) $query->where('status', $request->string('status')->toString());
        if ($request->filled('date_from')) $query->whereDate('document_date', '>=', $request->date('date_from'));
        if ($request->filled('date_to')) $query->whereDate('document_date', '<=', $request->date('date_to'));

        return DataTables::eloquent($query)
            ->addColumn('business_date', fn ($row) => $row->document_date?->format((string) ($settings->value('date_format') ?: 'd/m/Y')) ?: '-')
            ->addColumn('line_count', fn ($row) => $row->lines->count())
            ->addColumn('item_label', fn ($row) => $row->lines->map(fn ($line) => trim(($line->item?->code ?: '').' · '.($line->item?->name ?: '-'), ' ·'))->unique()->implode(', '))
            ->addColumn('quantity', fn ($row) => WmsDecimal::format($row->lines->sum('quantity')))
            ->addColumn('finished_receipt_label', fn ($row) => Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id')
                ? ($row->finishedReceipts->map(fn ($receipt) => $receipt->document_number.' · '.($labels[$receipt->status] ?? $receipt->status))->implode(' | ') ?: 'ยังไม่มีใบรับผลิต') : '-')
            ->addColumn('status_label', fn ($row) => $labels[$row->status] ?? $row->status)
            ->addColumn('show_url', fn ($row) => route('wms.production.material-issues.show', $row))
            ->addColumn('delete_url', fn ($row) => route('wms.production.material-issues.destroy', $row))
            ->addColumn('can_delete', fn ($row) => $row->status === 'DRAFT' && $request->user()->hasPermission('wms.issues.delete'))
            ->toJson();
    }

    public function itemOptions(Request $request, StockBalanceService $balances): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $q = trim((string) $request->input('q'));
        $rows = Item::query()->with('baseUom:id,code,name')->where('is_active', true)
            ->when($q, fn ($query) => $query->where(fn ($search) => $search->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))
            ->orderBy('code')->limit(31)->get(['id', 'code', 'name', 'base_uom_id']);

        return response()->json(['results' => $rows->take(30)->map(function (Item $item) use ($balances, $warehouseId): array {
            $balance = $balances->forItem($warehouseId, (int) $item->id, (int) $item->base_uom_id);
            return ['id' => $item->id, 'text' => $item->code.' · '.$item->name, 'uom_id' => $item->base_uom_id, 'uom_label' => trim(($item->baseUom?->code ?: '').' · '.($item->baseUom?->name ?: ''), ' ·'), 'available_quantity' => $balance['available'], 'available_label' => 'คงเหลือพร้อมใช้ '.WmsDecimal::format($balance['available'])];
        })->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function create(): View
    {
        return view('Wms::production.material-issues.create');
    }

    public function store(SaveIssueDocumentRequest $request, IssueReturnService $service, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $values = $request->validated();
        $values['issue_type'] = 'PRODUCTION';
        $document = $service->createIssue($values, $request->attributes->get('selectedWarehouse'), $request->user(), $sequences, $audit, $request);
        return response()->json(['status' => true, 'msg' => 'บันทึกร่างใบเบิกวัตถุดิบผลิตแล้ว', 'redirect' => route('wms.production.material-issues.show', $document)]);
    }

    public function show(Request $request, IssueDocument $document, GlobalSettings $settings): View
    {
        $this->scope($request, $document);
        abort_unless($document->issue_type === 'PRODUCTION', 404);
        $document->load(['warehouse:id,code,name', 'lines.item:id,code,name', 'lines.uom:id,code,name', 'lines.movement', 'lines.allocation', 'creator:id,name', 'issueReturns.lines.issueLine.item:id,code,name', 'issueReturns.lines.issueLine.uom:id,code,name']);
        if (Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id')) $document->load('finishedReceipts.lines.item:id,code,name');
        else $document->setRelation('finishedReceipts', collect());
        $stockBalances = StockBalance::query()->where('warehouse_id', $document->warehouse_id)->whereIn('item_id', $document->lines->pluck('item_id'))->get(['item_id', 'uom_id', 'available'])->keyBy(fn (StockBalance $balance): string => $balance->item_id.':'.$balance->uom_id);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)->latest('created_at')->latest('id')->get();
        return view('Wms::production.material-issues.show', ['document' => $document, 'history' => $history, 'stockBalances' => $stockBalances, 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y')]);
    }

    public function approve(Request $request, IssueDocument $document, IssueReturnService $service, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $document); $service->approve($document, $request->user(), $audit, $request);
        return response()->json(['status' => true, 'msg' => 'อนุมัติใบเบิกวัตถุดิบผลิตแล้ว']);
    }

    public function post(Request $request, IssueDocument $document, IssueReturnService $service, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $document); $service->post($document, $request->attributes->get('selectedWarehouse'), $request->user(), $audit, $request);
        return response()->json(['status' => true, 'msg' => 'ใบเบิกวัตถุดิบผลิตลง Stock แล้ว']);
    }

    public function cancel(Request $request, IssueDocument $document, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $document); $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        abort_unless(in_array($document->status, ['DRAFT', 'APPROVED'], true), 422, 'ยกเลิกได้เฉพาะใบเบิกที่ยังไม่ลง Stock');
        $before = $document->toArray(); $document->forceFill(['status' => 'VOID'])->save();
        $audit->record('wms.production_material_issue.cancelled', $document, $before, $document->fresh()->toArray(), $request->user(), $request);
        return response()->json(['status' => true, 'msg' => 'ยกเลิกใบเบิกวัตถุดิบผลิตแล้ว', 'redirect' => route('wms.production.material-issues.index')]);
    }

    public function destroy(Request $request, IssueDocument $document, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $document); abort_unless($document->status === 'DRAFT', 422, 'ลบได้เฉพาะใบเบิกร่าง');
        $before = $document->load('lines')->toArray(); $document->lines()->delete(); $document->delete();
        $audit->record('wms.production_material_issue.deleted', $document, $before, [], $request->user(), $request);
        return response()->json(['status' => true, 'msg' => 'ลบร่างใบเบิกวัตถุดิบผลิตแล้ว', 'redirect' => route('wms.production.material-issues.index')]);
    }

    private function scope(Request $request, IssueDocument $document): void
    {
        $warehouse = $request->attributes->get('selectedWarehouse'); $branch = $request->attributes->get('selectedBranch');
        abort_unless((int) $document->warehouse_id === (int) $warehouse->id && (int) $document->branch_id === (int) $branch->id && $document->issue_type === 'PRODUCTION', 404);
    }
}
