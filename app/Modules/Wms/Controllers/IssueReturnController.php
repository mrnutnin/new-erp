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
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Requests\SaveIssueDocumentRequest;
use App\Modules\Wms\Requests\SaveIssueReturnRequest;
use App\Modules\Wms\Services\IssueReturnService;
use App\Modules\Wms\Services\StockBalanceService;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class IssueReturnController extends Controller
{
    public function issuesIndex(Request $request): View
    {
        return view('Wms::issues.index', ['issueTypeOptions' => $this->issueTypeOptions($request), 'productionMode' => false]);
    }

    public function productionIssuesIndex(): View
    {
        return view('Wms::issues.index', ['issueTypeOptions' => null, 'productionMode' => true]);
    }

    public function issuesData(Request $request, GlobalSettings $settings): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $issueTypeOptions = $this->issueTypeOptions($request);
        $productionMode = $request->routeIs('wms.production.material-issues.*');
        $labels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิก', 'REVERSED' => 'กลับรายการแล้ว'];
        $relations = ['lines.item:id,code,name'];
        $relations[] = 'issueReturns.lines';
        $hasSourceIssueColumn = Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id');
        if ($productionMode && $hasSourceIssueColumn) $relations[] = 'finishedReceipts.lines';
        $query = IssueDocument::query()->with($relations)->where('warehouse_id', $warehouseId);
        $productionMode ? $query->where('issue_type', 'PRODUCTION') : $query->where('issue_type', '<>', 'PRODUCTION');
        if ($request->filled('status') && in_array($request->string('status')->toString(), ['DRAFT', 'APPROVED', 'POSTED', 'VOID', 'REVERSED'], true)) $query->where('status', $request->string('status')->toString());
        if (!$productionMode && $request->filled('issue_type') && $issueTypeOptions->has($request->string('issue_type')->toString())) $query->where('issue_type', $request->string('issue_type')->toString());
        if ($request->filled('date_from')) $query->whereDate('document_date', '>=', $request->date('date_from'));
        if ($request->filled('date_to')) $query->whereDate('document_date', '<=', $request->date('date_to'));

        return DataTables::eloquent($query)
            ->addColumn('business_date', fn ($row) => $row->document_date?->format((string) ($settings->value('date_format') ?: 'd/m/Y')) ?: '-')
            ->addColumn('line_count', fn ($row) => $row->lines->count())
            ->addColumn('item_label', fn ($row) => $row->lines->map(fn ($line) => trim(($line->item?->code ?: '').' · '.($line->item?->name ?: '-'), ' ·'))->unique()->implode(', '))
            ->addColumn('issue_type_label', fn ($row) => $issueTypeOptions->get($row->issue_type, $row->issue_type ?: '-'))
            ->addColumn('status_label', fn ($row) => $labels[$row->status] ?? $row->status)
            ->addColumn('quantity', fn ($row) => WmsDecimal::format($row->lines->sum('quantity')))
            ->addColumn('finished_receipt_label', fn ($row) => ! $productionMode || ! $hasSourceIssueColumn ? '-' : ($row->finishedReceipts->map(function ($receipt): string {
                $labels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก', 'REVERSED' => 'กลับรายการแล้ว'];
                return $receipt->document_number.' · '.($labels[$receipt->status] ?? $receipt->status).' · '.WmsDecimal::format($receipt->lines->sum('value'));
            })->implode(' | ') ?: 'ยังไม่มีใบรับผลิต'))
            ->addColumn('return_document_label', fn ($row) => $row->issueReturns->map(function ($return): string {
                $labels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิก'];
                return $return->document_number.' · '.($labels[$return->status] ?? $return->status).' · '.WmsDecimal::format($return->lines->sum('quantity'));
            })->implode(' | ') ?: 'ยังไม่มีใบรับคืน')
            ->addColumn('show_url', fn ($row) => $productionMode ? route('wms.production.material-issues.show', $row) : route('wms.issues.show', $row))
            ->addColumn('edit_url', fn ($row) => $productionMode ? route('wms.production.material-issues.edit', $row) : route('wms.issues.edit', $row))
            ->addColumn('can_edit', fn ($row) => $row->status === 'DRAFT' && $request->user()->hasPermission('wms.issues.update'))
            ->addColumn('can_approve', fn ($row) => $row->status === 'DRAFT' && $request->user()->hasPermission('wms.issues.approve'))
            ->addColumn('can_post', fn ($row) => $row->status === 'APPROVED' && $request->user()->hasPermission('wms.issues.post'))
            ->addColumn('can_delete', fn ($row) => $row->status === 'DRAFT' && $request->user()->hasPermission('wms.issues.delete'))
            ->toJson();
    }

    public function issueCreate(Request $request): View
    {
        $issueTypes = IssueType::query()->whereNull('warehouse_id')->where('is_active', true)->orderBy('name')->get(['code', 'name']);
        $productionMode = $request->routeIs('wms.production.material-issues.*');

        return view('Wms::issues.create', [
            'document' => null,
            'issueTypes' => $productionMode ? collect([(object) ['code' => 'PRODUCTION', 'name' => 'เบิกเข้าผลิต']]) : $issueTypes,
            'productionMode' => $productionMode,
        ]);
    }

    public function issueStore(SaveIssueDocumentRequest $request, IssueReturnService $service, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $productionMode = $request->routeIs('wms.production.material-issues.*');
        $values = $request->validated();
        if ($productionMode) {
            $values['issue_type'] = 'PRODUCTION';
        } elseif (($values['issue_type'] ?? null) === 'PRODUCTION') {
            throw ValidationException::withMessages(['issue_type' => 'เบิกเข้าผลิตต้องสร้างผ่านเมนูผลิตแบบ Manual']);
        }
        $document = $service->createIssue($values, $request->attributes->get('selectedWarehouse'), $request->user(), $sequences, $audit, $request);

        return response()->json([
            'status' => true,
            'msg' => $productionMode ? 'บันทึกร่างใบเบิกวัตถุดิบผลิตแล้ว' : 'บันทึกร่างใบเบิกสินค้าแล้ว',
            'redirect' => $productionMode ? route('wms.production.material-issues.show', $document) : route('wms.issues.show', $document),
        ]);
    }

    public function issueEdit(Request $request, IssueDocument $document): View
    {
        $this->scopeIssue($request, $document);
        abort_unless($document->status === 'DRAFT' && $document->issue_type !== 'PRODUCTION', 422, 'แก้ไขได้เฉพาะใบเบิกร่าง');
        $document->load(['lines.item:id,code,name', 'lines.uom:id,code,name']);

        return view('Wms::issues.create', [
            'document' => $document,
            'issueTypes' => IssueType::query()->whereNull('warehouse_id')->where('is_active', true)->orderBy('name')->get(['code', 'name']),
            'productionMode' => false,
        ]);
    }

    public function issueUpdate(SaveIssueDocumentRequest $request, IssueDocument $document, IssueReturnService $service, AuditLogger $audit): JsonResponse
    {
        $this->scopeIssue($request, $document);
        abort_unless($document->issue_type !== 'PRODUCTION', 404);
        $values = $request->validated();
        if (($values['issue_type'] ?? null) === 'PRODUCTION') {
            throw ValidationException::withMessages(['issue_type' => 'เบิกเข้าผลิตต้องสร้างผ่านเมนูผลิตแบบ Manual']);
        }
        $service->updateIssue($document, $values, $request->attributes->get('selectedWarehouse'), $request->user(), $audit, $request);

        return response()->json(['status' => true, 'msg' => 'แก้ไขร่างใบเบิกสินค้าแล้ว', 'redirect' => route('wms.issues.show', $document)]);
    }

    public function issueShow(Request $request, IssueDocument $document, GlobalSettings $settings): View
    {
        $this->scopeIssue($request, $document);
        $relations = ['warehouse:id,code,name', 'lines.item:id,code,name', 'lines.uom:id,code,name', 'lines.movement', 'lines.allocation', 'creator:id,name', 'photos.uploadedBy:id,name', 'issueReturns.lines.issueLine.item:id,code,name', 'issueReturns.lines.issueLine.uom:id,code,name'];
        if ($document->issue_type === 'PRODUCTION' && Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id')) {
            $relations[] = 'finishedReceipts.lines.item:id,code,name';
            $relations[] = 'finishedReceipts.lines.uom:id,code,name';
        }
        $document->load($relations);
        if ($document->issue_type !== 'PRODUCTION' || ! Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id')) {
            $document->setRelation('finishedReceipts', collect());
        }
        $stockBalances = StockBalance::query()
            ->where('warehouse_id', $document->warehouse_id)
            ->whereIn('item_id', $document->lines->pluck('item_id'))
            ->get(['item_id', 'uom_id', 'available'])
            ->keyBy(fn (StockBalance $balance): string => $balance->item_id.':'.$balance->uom_id);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)->latest('created_at')->latest('id')->get();

        return view('Wms::issues.show', [
            'document' => $document,
            'history' => $history,
            'stockBalances' => $stockBalances,
            'productionMode' => $document->issue_type === 'PRODUCTION',
            'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
        ]);
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

    public function issueCancel(Request $request, IssueDocument $document, AuditLogger $audit, IssueReturnService $service): JsonResponse
    {
        $this->scopeIssue($request, $document);
        $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        abort_unless(in_array($document->status, ['DRAFT', 'APPROVED'], true), 422, 'ยกเลิกได้เฉพาะใบเบิกที่ยังไม่ลง Stock');

        $before = $document->toArray();
        $document->forceFill(['status' => 'VOID'])->save();
        if ($document->issue_type === 'PRODUCTION') $service->syncProductionOrderAfterMaterialIssueVoided($document->fresh(), $request->user());
        $audit->record('wms.issue.cancelled', $document, $before, $document->fresh()->toArray(), $request->user(), $request);

        $production = $document->issue_type === 'PRODUCTION';

        return response()->json([
            'status' => true,
            'msg' => $production ? 'ยกเลิกใบเบิกวัตถุดิบผลิตแล้ว' : 'ยกเลิกใบเบิกสินค้าแล้ว',
            'redirect' => $production ? route('wms.production.material-issues.index') : route('wms.issues.index'),
        ]);
    }

    public function issueDelete(Request $request, IssueDocument $document, AuditLogger $audit): JsonResponse
    {
        $this->scopeIssue($request, $document);
        abort_unless($document->status === 'DRAFT', 422, 'ลบได้เฉพาะใบเบิกร่าง');
        $before = $document->load('lines')->toArray();
        $document->lines()->delete();
        $document->delete();
        $audit->record('wms.issue.deleted', $document, $before, [], $request->user(), $request);

        $production = $document->issue_type === 'PRODUCTION';

        return response()->json([
            'status' => true,
            'msg' => $production ? 'ลบร่างใบเบิกวัตถุดิบผลิตแล้ว' : 'ลบร่างใบเบิกสินค้าแล้ว',
            'redirect' => $production ? route('wms.production.material-issues.index') : route('wms.issues.index'),
        ]);
    }

    public function returnsIndex(): View
    {
        return view('Wms::issue-returns.index');
    }

    public function returnsData(Request $request, GlobalSettings $settings): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $labels = ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลง Stock แล้ว', 'VOID' => 'ยกเลิก', 'REVERSED' => 'กลับรายการแล้ว'];
        $query = IssueReturn::query()->with(['issue:id,document_number,issue_type', 'lines'])->where('warehouse_id', $warehouseId)
            ->whereHas('issue', fn ($issue) => $issue->where('issue_type', '<>', 'PRODUCTION'));
        if ($request->filled('status') && in_array($request->string('status')->toString(), ['DRAFT', 'APPROVED', 'POSTED', 'VOID', 'REVERSED'], true)) $query->where('status', $request->string('status')->toString());
        if ($request->filled('date_from')) $query->whereDate('document_date', '>=', $request->date('date_from'));
        if ($request->filled('date_to')) $query->whereDate('document_date', '<=', $request->date('date_to'));

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
                ->where('issue_type', '<>', 'PRODUCTION')
                ->where('status', 'POSTED')
                ->find($request->integer('issue_document_id'));
        }

        return view('Wms::issue-returns.create', ['selectedIssue' => $selectedIssue]);
    }

    public function returnStore(SaveIssueReturnRequest $request, IssueReturnService $service, DocumentSequenceService $sequences, AuditLogger $audit): JsonResponse
    {
        $issue = IssueDocument::query()->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->findOrFail($request->integer('issue_document_id'));
        abort_unless($issue->issue_type !== 'PRODUCTION', 422, 'ใบเบิกวัตถุดิบผลิตต้องสร้างใบรับคืนผ่านเมนูผลิตแบบ Manual');
        $document = $service->createReturn($request->validated(), $request->attributes->get('selectedWarehouse'), $request->user(), $sequences, $audit, $request);

        return response()->json(['status' => true, 'msg' => 'บันทึกร่างใบรับคืนจากการเบิกแล้ว', 'redirect' => route('wms.issue-returns.show', $document)]);
    }

    public function returnShow(Request $request, IssueReturn $document, GlobalSettings $settings): View
    {
        $this->scopeReturn($request, $document);
        $document->load(['warehouse:id,code,name', 'issue:id,document_number,document_date,issue_type,status,reason,warehouse_id', 'issue.lines.item:id,code,name', 'issue.lines.uom:id,code,name', 'lines.issueLine.item:id,code,name', 'lines.issueLine.uom:id,code,name', 'lines.movement', 'lines.allocation', 'lines.sourceAllocations.sourceAllocation', 'lines.sourceAllocations.movement', 'lines.sourceAllocations.allocation', 'photos.uploadedBy:id,name']);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $document->getMorphClass())->where('subject_id', $document->id)->latest('created_at')->latest('id')->get();

        return view('Wms::issue-returns.show', ['document' => $document, 'history' => $history, 'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y')]);
    }

    public function returnApprove(Request $request, IssueReturn $document, IssueReturnService $service, AuditLogger $audit): JsonResponse
    {
        $this->scopeReturn($request, $document);
        $service->approve($document, $request->user(), $audit, $request);

        return response()->json(['status' => true, 'msg' => 'อนุมัติใบรับคืนแล้ว']);
    }

    public function returnCancel(Request $request, IssueReturn $document, AuditLogger $audit): JsonResponse
    {
        $this->scopeReturn($request, $document);
        $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        abort_unless(in_array($document->status, ['DRAFT', 'APPROVED'], true), 422, 'ยกเลิกได้เฉพาะใบรับคืนที่ยังไม่ลง Stock');

        $before = $document->toArray();
        $document->forceFill(['status' => 'VOID'])->save();
        $audit->record('wms.issue_return.cancelled', $document, $before, $document->fresh()->toArray(), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'ยกเลิกใบรับคืนแล้ว', 'redirect' => route('wms.issue-returns.index')]);
    }

    public function returnPost(Request $request, IssueReturn $document, IssueReturnService $service, AuditLogger $audit): JsonResponse
    {
        $this->scopeReturn($request, $document);
        $service->postReturn($document, $request->attributes->get('selectedWarehouse'), $request->user(), $audit, $request);

        return response()->json(['status' => true, 'msg' => 'ใบรับคืนลง Stock แล้ว']);
    }

    public function returnReverse(Request $request, IssueReturn $document, IssueReturnService $service, AuditLogger $audit): JsonResponse
    {
        $this->scopeReturn($request, $document);
        $values = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:500']]);
        $service->reverseReturn($document, $request->user(), $values['reason'], $audit, $request);

        return response()->json(['status' => true, 'msg' => 'กลับรายการใบรับคืนแล้ว', 'redirect' => route('wms.issue-returns.show', $document)]);
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

    public function itemOptions(Request $request, StockBalanceService $balances): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $q = trim((string) $request->input('q'));
        $rows = Item::query()->with('baseUom:id,code,name')->where('is_active', true)->when($q, fn ($x) => $x->where(fn ($y) => $y->where('code', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%")))->orderBy('code')->limit(31)->get(['id', 'code', 'name', 'base_uom_id']);

        return response()->json(['results' => $rows->take(30)->map(function ($x) use ($balances, $warehouseId) {
            $balance = $balances->forItem($warehouseId, (int) $x->id, (int) $x->base_uom_id);

            return ['id' => $x->id, 'text' => $x->code.' · '.$x->name, 'uom_id' => $x->base_uom_id, 'uom_label' => $x->baseUom?->code.' · '.$x->baseUom?->name, 'available_quantity' => $balance['available'], 'available_label' => 'คงเหลือพร้อมใช้ '.WmsDecimal::format($balance['available'])];
        })->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function issueOptions(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q'));
        $rows = IssueDocument::query()->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->where('status', 'POSTED')->when($q, fn ($x) => $x->where('document_number', 'like', "%{$q}%"))->latest('id')->limit(31)->get(['id', 'document_number', 'document_date']);

        return response()->json(['results' => $rows->take(30)->map(fn ($x) => ['id' => $x->id, 'text' => $x->document_number.' · '.$x->document_date?->format('d/m/Y')])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function issueLineOptions(Request $request): JsonResponse
    {
        $issue = IssueDocument::query()->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->where('issue_type', '<>', 'PRODUCTION')->where('status', 'POSTED')->findOrFail($request->integer('issue_document_id'));
        $rows = IssueLine::query()->with(['item:id,code,name', 'uom:id,code,name'])->where('document_id', $issue->id)->get();

        return response()->json(['results' => $rows->map(function ($x) {
            $used = IssueReturnLine::where('issue_line_id', $x->id)->whereHas('return', fn ($q) => $q->whereIn('status', ['APPROVED', 'POSTED']))->sum('quantity');
            $remaining = max(0, (float) $x->quantity - (float) $used);

            return ['id' => $x->id, 'text' => ($x->item?->code ?: '-').' · '.($x->item?->name ?: '-').' (เหลือ '.WmsDecimal::format($remaining).' '.($x->uom?->code ?: '').')', 'remaining' => $remaining];
        })->filter(fn ($x) => $x['remaining'] > 0)->values()]);
    }

    private function issueTypeOptions(Request $request)
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $defaults = collect(['GENERAL' => 'เบิกทั่วไป', 'PRODUCTION' => 'เบิกเข้าผลิต', 'PROJECT' => 'เบิกโครงการ']);
        $configured = IssueType::query()
            ->whereNull('warehouse_id')
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['code', 'name'])
            ->mapWithKeys(fn (IssueType $type) => [$type->code => $type->name]);

        return $defaults->merge($configured);
    }

    private function scopeIssue(Request $request, IssueDocument $document): void
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $branch = $request->attributes->get('selectedBranch');
        abort_unless((int) $document->warehouse_id === (int) $warehouse->id && (int) $document->branch_id === (int) $branch->id, 404);
    }

    private function scopeReturn(Request $request, IssueReturn $document): void
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $branch = $request->attributes->get('selectedBranch');
        abort_unless((int) $document->warehouse_id === (int) $warehouse->id && (int) $document->branch_id === (int) $branch->id, 404);
    }
}
