<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\IssueLine;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Wms\Models\IssueReturnLine;
use App\Modules\Wms\Models\IssueType;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Requests\SaveIssueDocumentRequest;
use App\Modules\Wms\Requests\SaveIssueReturnRequest;
use App\Modules\Wms\Services\IssueReturnService;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class IssueReturnController extends Controller
{
    public function issuesIndex(): View
    {
        return view('Wms::issues.index');
    }

    public function issuesData(Request $request, GlobalSettings $settings): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $labels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิก'];
        $query = IssueDocument::query()->with(['lines.item:id,code,name'])->where('warehouse_id', $warehouseId)->latest('id');

        return DataTables::eloquent($query)
            ->addColumn('business_date', fn ($row) => $row->document_date?->format((string) ($settings->value('date_format') ?: 'd/m/Y')) ?: '-')
            ->addColumn('line_count', fn ($row) => $row->lines->count())
            ->addColumn('item_label', fn ($row) => $row->lines->map(fn ($line) => trim(($line->item?->code ?: '').' · '.($line->item?->name ?: '-'), ' ·'))->unique()->implode(', '))
            ->addColumn('status_label', fn ($row) => $labels[$row->status] ?? $row->status)
            ->addColumn('quantity', fn ($row) => WmsDecimal::format($row->lines->sum('quantity')))
            ->addColumn('show_url', fn ($row) => route('wms.issues.show', $row))
            ->addColumn('can_approve', fn ($row) => $row->status === 'DRAFT' && $request->user()->hasPermission('wms.issues.approve'))
            ->addColumn('can_post', fn ($row) => $row->status === 'APPROVED' && $request->user()->hasPermission('wms.issues.post'))
            ->addColumn('can_delete', fn ($row) => $row->status === 'DRAFT' && $request->user()->hasPermission('wms.issues.delete'))
            ->toJson();
    }

    public function issueCreate(Request $request): View
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $issueTypes = IssueType::query()->where('warehouse_id', $warehouseId)->where('is_active', true)->orderBy('name')->get(['code', 'name']);

        return view('Wms::issues.create', ['document' => null, 'issueTypes' => $issueTypes]);
    }

    public function issueStore(SaveIssueDocumentRequest $request, IssueReturnService $service, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $document = $service->createIssue($request->validated(), $request->attributes->get('selectedWarehouse'), $request->user(), $sequences, $audit, $request);

        return response()->json(['status' => true, 'msg' => 'บันทึกร่างใบเบิกสินค้าแล้ว', 'redirect' => route('wms.issues.show', $document)]);
    }

    public function issueShow(Request $request, IssueDocument $document, GlobalSettings $settings): View
    {
        $this->scopeIssue($request, $document);
        $document->load(['warehouse:id,code,name', 'lines.item:id,code,name', 'lines.uom:id,code,name', 'lines.movement', 'lines.allocation', 'creator:id,name']);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)->latest('created_at')->latest('id')->get();

        return view('Wms::issues.show', ['document' => $document, 'history' => $history, 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y')]);
    }

    public function issueApprove(Request $request, IssueDocument $document, IssueReturnService $service, AuditLogger $audit): JsonResponse
    {
        $this->scopeIssue($request, $document);
        $service->approve($document, $request->user(), $audit, $request);

        return response()->json(['status' => true, 'msg' => 'อนุมัติใบเบิกสินค้าแล้ว']);
    }

    public function issuePost(Request $request, IssueDocument $document, IssueReturnService $service, AuditLogger $audit): JsonResponse
    {
        $this->scopeIssue($request, $document);
        $service->post($document, $request->attributes->get('selectedWarehouse'), $request->user(), $audit, $request);

        return response()->json(['status' => true, 'msg' => 'ใบเบิกสินค้าลง Stock แล้ว']);
    }

    public function issueDelete(Request $request, IssueDocument $document, AuditLogger $audit): JsonResponse
    {
        $this->scopeIssue($request, $document);
        abort_unless($document->status === 'DRAFT', 422, 'ลบได้เฉพาะใบเบิกร่าง');
        $before = $document->load('lines')->toArray();
        $document->lines()->delete();
        $document->delete();
        $audit->record('wms.issue.deleted', $document, $before, [], $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ลบร่างใบเบิกสินค้าแล้ว', 'redirect' => route('wms.issues.index')]);
    }

    public function returnsIndex(): View
    {
        return view('Wms::issue-returns.index');
    }

    public function returnsData(Request $request, GlobalSettings $settings): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $labels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิก'];
        $query = IssueReturn::query()->with(['issue:id,document_number', 'lines'])->where('warehouse_id', $warehouseId)->latest('id');

        return DataTables::eloquent($query)
            ->addColumn('business_date', fn ($row) => $row->document_date?->format((string) ($settings->value('date_format') ?: 'd/m/Y')) ?: '-')
            ->addColumn('issue_number', fn ($row) => $row->issue?->document_number ?: '-')
            ->addColumn('line_count', fn ($row) => $row->lines->count())
            ->addColumn('status_label', fn ($row) => $labels[$row->status] ?? $row->status)
            ->addColumn('quantity', fn ($row) => WmsDecimal::format($row->lines->sum('quantity')))
            ->addColumn('show_url', fn ($row) => route('wms.issue-returns.show', $row))
            ->addColumn('can_approve', fn ($row) => $row->status === 'DRAFT' && $request->user()->hasPermission('wms.issue-returns.approve'))
            ->addColumn('can_post', fn ($row) => $row->status === 'APPROVED' && $request->user()->hasPermission('wms.issue-returns.post'))
            ->addColumn('can_delete', fn ($row) => $row->status === 'DRAFT' && $request->user()->hasPermission('wms.issue-returns.delete'))
            ->toJson();
    }

    public function returnCreate(Request $request): View
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $selectedIssue = null;

        if ($request->filled('issue_document_id')) {
            $selectedIssue = IssueDocument::query()
                ->where('warehouse_id', $warehouseId)
                ->where('status', 'POSTED')
                ->find($request->integer('issue_document_id'));
        }

        return view('Wms::issue-returns.create', ['selectedIssue' => $selectedIssue]);
    }

    public function returnStore(SaveIssueReturnRequest $request, IssueReturnService $service, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $document = $service->createReturn($request->validated(), $request->attributes->get('selectedWarehouse'), $request->user(), $sequences, $audit, $request);

        return response()->json(['status' => true, 'msg' => 'บันทึกร่างใบรับคืนจากการเบิกแล้ว', 'redirect' => route('wms.issue-returns.show', $document)]);
    }

    public function returnShow(Request $request, IssueReturn $document, GlobalSettings $settings): View
    {
        $this->scopeReturn($request, $document);
        $document->load(['warehouse:id,code,name', 'issue:id,document_number', 'lines.issueLine.item:id,code,name', 'lines.issueLine.uom:id,code,name', 'lines.movement', 'lines.allocation', 'lines.sourceAllocations.sourceAllocation', 'lines.sourceAllocations.movement', 'lines.sourceAllocations.allocation']);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)->latest('created_at')->latest('id')->get();

        return view('Wms::issue-returns.show', ['document' => $document, 'history' => $history, 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y')]);
    }

    public function returnApprove(Request $request, IssueReturn $document, IssueReturnService $service, AuditLogger $audit): JsonResponse
    {
        $this->scopeReturn($request, $document);
        $service->approve($document, $request->user(), $audit, $request);

        return response()->json(['status' => true, 'msg' => 'อนุมัติใบรับคืนแล้ว']);
    }

    public function returnPost(Request $request, IssueReturn $document, IssueReturnService $service, AuditLogger $audit): JsonResponse
    {
        $this->scopeReturn($request, $document);
        $service->postReturn($document, $request->attributes->get('selectedWarehouse'), $request->user(), $audit, $request);

        return response()->json(['status' => true, 'msg' => 'ใบรับคืนลง Stock แล้ว']);
    }

    public function returnDelete(Request $request, IssueReturn $document, AuditLogger $audit): JsonResponse
    {
        $this->scopeReturn($request, $document);
        abort_unless($document->status === 'DRAFT', 422, 'ลบได้เฉพาะใบรับคืนร่าง');
        $before = $document->load('lines')->toArray();
        $document->lines()->delete();
        $document->delete();
        $audit->record('wms.issue_return.deleted', $document, $before, [], $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ลบร่างใบรับคืนแล้ว', 'redirect' => route('wms.issue-returns.index')]);
    }

    public function itemOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = Item::query()->with('baseUom:id,code,name')->where('is_active', true)->when($q, fn ($x) => $x->where(fn ($y) => $y->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))->orderBy('code')->limit(31)->get(['id', 'code', 'name', 'base_uom_id']);

        return response()->json(['results' => $rows->take(30)->map(fn ($x) => ['id' => $x->id, 'text' => $x->code.' · '.$x->name, 'uom_id' => $x->base_uom_id, 'uom_label' => $x->baseUom?->code.' · '.$x->baseUom?->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function issueOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = IssueDocument::query()->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->where('status', 'POSTED')->when($q, fn ($x) => $x->where('document_number', 'like', "%{$q}%"))->latest('id')->limit(31)->get(['id', 'document_number', 'document_date']);

        return response()->json(['results' => $rows->take(30)->map(fn ($x) => ['id' => $x->id, 'text' => $x->document_number.' · '.$x->document_date?->format('d/m/Y')])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function issueLineOptions(Request $request): JsonResponse
    {
        $issue = IssueDocument::query()->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->where('status', 'POSTED')->findOrFail($request->integer('issue_document_id'));
        $rows = IssueLine::query()->with(['item:id,code,name', 'uom:id,code,name'])->where('document_id', $issue->id)->get();

        return response()->json(['results' => $rows->map(function ($x) {
            $used = IssueReturnLine::where('issue_line_id', $x->id)->whereHas('return', fn ($q) => $q->whereIn('status', ['APPROVED', 'POSTED']))->sum('quantity');
            $remaining = max(0, (float) $x->quantity - (float) $used);

            return ['id' => $x->id, 'text' => ($x->item?->code ?: '-').' · '.($x->item?->name ?: '-').' (เหลือ '.WmsDecimal::format($remaining).' '.($x->uom?->code ?: '').')', 'remaining' => $remaining];
        })->filter(fn ($x) => $x['remaining'] > 0)->values()]);
    }

    private function scopeIssue(Request $request, IssueDocument $document): void
    {
        abort_unless((int) $document->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
    }

    private function scopeReturn(Request $request, IssueReturn $document): void
    {
        abort_unless((int) $document->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
    }
}
