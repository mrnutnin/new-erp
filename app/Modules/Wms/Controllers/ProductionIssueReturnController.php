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
use App\Modules\Wms\Requests\SaveIssueReturnRequest;
use App\Modules\Wms\Services\IssueReturnService;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class ProductionIssueReturnController extends Controller
{
    public function index(): View { return view('Wms::production.issue-returns.index'); }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $labels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิกเอกสาร', 'REVERSED' => 'ยกเลิกเอกสารแล้ว'];
        $query = IssueReturn::query()->with(['issue:id,document_number,issue_type', 'lines'])->where('warehouse_id', $warehouseId)->whereHas('issue', fn ($issue) => $issue->where('issue_type', 'PRODUCTION'))->latest('id');
        if ($request->filled('status')) $query->where('status', $request->string('status')->toString());
        if ($request->filled('date_from')) $query->whereDate('document_date', '>=', $request->date('date_from'));
        if ($request->filled('date_to')) $query->whereDate('document_date', '<=', $request->date('date_to'));
        return DataTables::eloquent($query)
            ->addColumn('business_date', fn ($row) => $row->document_date?->format((string) ($settings->value('date_format') ?: 'd/m/Y')) ?: '-')
            ->addColumn('issue_number', fn ($row) => $row->issue?->document_number ?: '-')
            ->addColumn('quantity', fn ($row) => WmsDecimal::format($row->lines->sum('quantity')))
            ->addColumn('status_label', fn ($row) => $labels[$row->status] ?? $row->status)
            ->addColumn('show_url', fn ($row) => route('wms.production.issue-returns.show', $row))
            ->addColumn('delete_url', fn ($row) => route('wms.production.issue-returns.destroy', $row))
            ->addColumn('can_delete', fn ($row) => $row->status === 'DRAFT' && $request->user()->hasPermission('wms.issue-returns.delete'))
            ->toJson();
    }

    public function issueOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = IssueDocument::query()->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->where('issue_type', 'PRODUCTION')->where('status', 'POSTED')->when($q, fn ($query) => $query->where('document_number', 'like', "%{$q}%"))->latest('id')->limit(31)->get(['id', 'document_number', 'document_date']);
        return response()->json(['results' => $rows->take(30)->map(fn ($row) => ['id' => $row->id, 'text' => $row->document_number.' · '.$row->document_date?->format('d/m/Y')])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function issueLineOptions(Request $request): JsonResponse
    {
        $issue = IssueDocument::query()->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->where('issue_type', 'PRODUCTION')->where('status', 'POSTED')->findOrFail($request->integer('issue_document_id'));
        $rows = IssueLine::query()->with(['item:id,code,name', 'uom:id,code,name'])->where('document_id', $issue->id)->get();
        return response()->json(['results' => $rows->map(function ($line): array {
            $used = IssueReturnLine::query()->where('issue_line_id', $line->id)->whereHas('return', fn ($return) => $return->whereIn('status', ['APPROVED', 'POSTED']))->sum('quantity');
            $remaining = max(0, (float) $line->quantity - (float) $used);
            return ['id' => $line->id, 'text' => ($line->item?->code ?: '-').' · '.($line->item?->name ?: '-').' (เหลือ '.WmsDecimal::format($remaining).' '.($line->uom?->code ?: '').')', 'remaining' => $remaining];
        })->filter(fn ($line) => $line['remaining'] > 0)->values()]);
    }

    public function create(Request $request): View
    {
        $selectedIssue = null;
        if ($request->filled('issue_document_id')) $selectedIssue = IssueDocument::query()->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->where('issue_type', 'PRODUCTION')->where('status', 'POSTED')->find($request->integer('issue_document_id'));
        return view('Wms::production.issue-returns.create', compact('selectedIssue'));
    }

    public function store(SaveIssueReturnRequest $request, IssueReturnService $service, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $values = $request->validated();
        $issue = IssueDocument::query()->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->where('issue_type', 'PRODUCTION')->where('status', 'POSTED')->findOrFail($values['issue_document_id']);
        $document = $service->createReturn($values, $request->attributes->get('selectedWarehouse'), $request->user(), $sequences, $audit, $request);
        return response()->json(['status' => true, 'msg' => 'บันทึกร่างใบรับคืนวัตถุดิบผลิตแล้ว', 'redirect' => route('wms.production.issue-returns.show', $document)]);
    }

    public function show(Request $request, IssueReturn $document, GlobalSettings $settings): View
    {
        $this->scope($request, $document);
        $document->load(['warehouse:id,code,name', 'issue:id,document_number,document_date,issue_type,status,reason,warehouse_id', 'issue.lines.item:id,code,name', 'issue.lines.uom:id,code,name', 'lines.issueLine.item:id,code,name', 'lines.issueLine.uom:id,code,name', 'lines.movement', 'lines.allocation']);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)->latest('created_at')->latest('id')->get();
        return view('Wms::production.issue-returns.show', ['document' => $document, 'history' => $history, 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y')]);
    }

    public function approve(Request $request, IssueReturn $document, IssueReturnService $service, AuditLogger $audit): JsonResponse { $this->scope($request, $document); $service->approve($document, $request->user(), $audit, $request); return response()->json(['status' => true, 'msg' => 'อนุมัติใบรับคืนวัตถุดิบผลิตแล้ว']); }
    public function post(Request $request, IssueReturn $document, IssueReturnService $service, AuditLogger $audit): JsonResponse { $this->scope($request, $document); $service->postReturn($document, $request->attributes->get('selectedWarehouse'), $request->user(), $audit, $request); return response()->json(['status' => true, 'msg' => 'ใบรับคืนวัตถุดิบผลิตลง Stock แล้ว']); }
    public function cancel(Request $request, IssueReturn $document, AuditLogger $audit): JsonResponse { $this->scope($request, $document); $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]); abort_unless(in_array($document->status, ['DRAFT', 'APPROVED'], true), 422, 'ยกเลิกได้เฉพาะใบรับคืนที่ยังไม่ลง Stock'); $before = $document->toArray(); $document->forceFill(['status' => 'VOID'])->save(); $audit->record('wms.production_issue_return.cancelled', $document, $before, $document->fresh()->toArray(), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'ยกเลิกใบรับคืนวัตถุดิบผลิตแล้ว', 'redirect' => route('wms.production.issue-returns.index')]); }
    public function reverse(Request $request, IssueReturn $document, IssueReturnService $service, AuditLogger $audit): JsonResponse { $this->scope($request, $document); $values = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]); $service->reverseReturn($document, $request->user(), $values['reason'], $audit, $request); return response()->json(['status' => true, 'msg' => 'กลับรายการใบรับคืนวัตถุดิบผลิตแล้ว', 'redirect' => route('wms.production.issue-returns.show', $document)]); }
    public function destroy(Request $request, IssueReturn $document, AuditLogger $audit): JsonResponse { $this->scope($request, $document); abort_unless($document->status === 'DRAFT', 422, 'ลบได้เฉพาะใบรับคืนร่าง'); $before = $document->load('lines')->toArray(); $document->lines()->delete(); $document->delete(); $audit->record('wms.production_issue_return.deleted', $document, $before, [], $request->user(), $request); return response()->json(['status' => true, 'msg' => 'ลบร่างใบรับคืนวัตถุดิบผลิตแล้ว', 'redirect' => route('wms.production.issue-returns.index')]); }

    private function scope(Request $request, IssueReturn $document): void { $warehouse = $request->attributes->get('selectedWarehouse'); $branch = $request->attributes->get('selectedBranch'); abort_unless((int) $document->warehouse_id === (int) $warehouse->id && (int) $document->branch_id === (int) $branch->id && $document->issue()->where('issue_type', 'PRODUCTION')->exists(), 404); }
}
