<?php

namespace App\Modules\Production\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\InventoryAdjustmentDocument;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Services\IssueReturnService;
use App\Modules\Wms\Services\ManualProductionReceiptPostingService;
use App\Modules\Wms\Services\ProductionFinishedReceiptDocumentService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class DocumentQueueController extends Controller
{
    private const STATUS_LABELS = [
        'DRAFT' => 'ร่าง · รออนุมัติ',
        'APPROVED' => 'อนุมัติแล้ว · รอลง Stock และ GL',
        'POSTED' => 'ลง Stock และ GL แล้ว',
        'VOID' => 'ยกเลิกเอกสาร',
    ];

    private const STATUS_CLASSES = [
        'DRAFT' => 'neutral',
        'APPROVED' => 'info',
        'POSTED' => 'success',
        'VOID' => 'danger',
    ];

    public function materialIssues(Request $request): View
    {
        $status = $this->queueStatus($request);
        $label = $this->statusLabel($status);

        return view('Production::document-queues.index', [
            'title' => 'ใบเบิกวัตถุดิบผลิต',
            'status' => $status,
            'statusLabel' => $label,
            'dataUrl' => route('production.document-queues.material-issues.data', ['status' => $status]),
            'kind' => 'issue',
        ]);
    }

    public function materialIssuesData(Request $request, GlobalSettings $settings): JsonResponse
    {
        $status = $this->queueStatus($request);
        $warehouse = $request->attributes->get('selectedWarehouse');
        $branch = $request->attributes->get('selectedBranch');
        $labels = self::STATUS_LABELS;
        $query = IssueDocument::query()->with(['lines.item:id,code,name'])
            ->where('branch_id', $branch->id)->where('warehouse_id', $warehouse->id)
            ->where('issue_type', 'PRODUCTION')->where('status', $status);

        $dateFormat = (string) ($settings->value('date_format') ?: 'd/m/Y');

        return DataTables::eloquent($query)
            ->addColumn('business_date', fn (IssueDocument $document) => $document->document_date?->format($dateFormat) ?: '-')
            ->addColumn('item_summary', fn (IssueDocument $document) => $document->lines->map(fn ($line) => trim(($line->item?->code ?? '').' · '.($line->item?->name ?? ''), ' ·'))->unique()->implode(', '))
            ->addColumn('status_label', fn () => $labels[$status])
            ->addColumn('show_url', fn (IssueDocument $document) => route('production.document-queues.material-issues.show', ['document' => $document, 'queue_status' => $status]))
            ->orderColumn('document_number', 'document_number $1, id $1')
            ->orderColumn('document_date', 'document_date $1, id $1')
            ->filterColumn('document_date', fn ($query, string $keyword) => $this->filterDate($query, $dateFormat, $keyword))
            ->filterColumn('item_summary', fn ($query, string $keyword) => $this->filterItemSummary($query, $keyword))
            ->filterColumn('status_label', fn ($query, string $keyword) => $this->filterStatusLabel($query, $labels[$status], $keyword))
            ->toJson();
    }

    public function finishedReceipts(Request $request): View
    {
        $status = $this->queueStatus($request);
        $label = $this->statusLabel($status);

        return view('Production::document-queues.index', [
            'title' => 'ใบรับสินค้าผลิตเสร็จ',
            'status' => $status,
            'statusLabel' => $label,
            'dataUrl' => route('production.document-queues.finished-receipts.data', ['status' => $status]),
            'kind' => 'receipt',
        ]);
    }

    public function finishedReceiptsData(Request $request, GlobalSettings $settings): JsonResponse
    {
        $status = $this->queueStatus($request);
        $warehouse = $request->attributes->get('selectedWarehouse');
        $branch = $request->attributes->get('selectedBranch');
        $labels = self::STATUS_LABELS;
        $query = InventoryAdjustmentDocument::query()->with(['lines.item:id,code,name'])
            ->where('branch_id', $branch->id)->where('warehouse_id', $warehouse->id)
            ->where('document_context', 'PRODUCTION_RECEIPT')->where('status', $status);

        $dateFormat = (string) ($settings->value('date_format') ?: 'd/m/Y');

        return DataTables::eloquent($query)
            ->addColumn('business_date', fn (InventoryAdjustmentDocument $document) => $document->document_date?->format($dateFormat) ?: '-')
            ->addColumn('item_summary', fn (InventoryAdjustmentDocument $document) => $document->lines->map(fn ($line) => trim(($line->item?->code ?? '').' · '.($line->item?->name ?? ''), ' ·'))->unique()->implode(', '))
            ->addColumn('status_label', fn () => $labels[$status])
            ->addColumn('show_url', fn (InventoryAdjustmentDocument $document) => route('production.document-queues.finished-receipts.show', ['document' => $document, 'queue_status' => $status]))
            ->orderColumn('document_number', 'document_number $1, id $1')
            ->orderColumn('document_date', 'document_date $1, id $1')
            ->filterColumn('document_date', fn ($query, string $keyword) => $this->filterDate($query, $dateFormat, $keyword))
            ->filterColumn('item_summary', fn ($query, string $keyword) => $this->filterItemSummary($query, $keyword))
            ->filterColumn('status_label', fn ($query, string $keyword) => $this->filterStatusLabel($query, $labels[$status], $keyword))
            ->toJson();
    }

    public function showIssue(Request $request, IssueDocument $document, GlobalSettings $settings): View
    {
        $this->scopeIssue($request, $document);
        $document->load(['warehouse:id,code,name', 'lines.item:id,code,name', 'lines.uom:id,code,name']);

        return $this->showView($request, $document, 'issue', $settings);
    }

    public function showReceipt(Request $request, InventoryAdjustmentDocument $document, GlobalSettings $settings, ManualProductionReceiptPostingService $posting): View
    {
        $this->scopeReceipt($request, $document);
        $document->load(['warehouse:id,code,name', 'lines.item:id,code,name', 'lines.uom:id,code,name']);
        $document->setRelation('sourceIssue', $this->sourceIssue($request, $document));
        $readiness = $document->status === 'APPROVED' ? $posting->preflight($document->toArray()) : null;

        return $this->showView($request, $document, 'receipt', $settings, $readiness);
    }

    public function approveIssue(Request $request, IssueDocument $document, IssueReturnService $issues, AuditLogger $audit): JsonResponse
    {
        $this->scopeIssue($request, $document);
        $issues->approve($document, $request->user(), $audit, $request);

        return $this->success('อนุมัติใบเบิกวัตถุดิบผลิตแล้ว');
    }

    public function postIssue(Request $request, IssueDocument $document, IssueReturnService $issues, AuditLogger $audit): JsonResponse
    {
        $this->scopeIssue($request, $document);
        $issues->post($document, $request->attributes->get('selectedWarehouse'), $request->user(), $audit, $request);

        return $this->success('ใบเบิกวัตถุดิบผลิตลง Stock และ GL แล้ว');
    }

    public function approveReceipt(Request $request, InventoryAdjustmentDocument $document, ProductionFinishedReceiptDocumentService $receipts): JsonResponse
    {
        $this->scopeReceipt($request, $document);
        $receipts->approve($document, $request->user(), $request);

        return $this->success('อนุมัติใบรับผลิตแล้ว');
    }

    public function postReceipt(Request $request, InventoryAdjustmentDocument $document, ManualProductionReceiptPostingService $posting): JsonResponse
    {
        $this->scopeReceipt($request, $document);
        $posting->post($document, $request->attributes->get('selectedWarehouse'), $request->user(), $request);

        return $this->success('ใบรับผลิตลง Stock และ GL แล้ว');
    }

    private function showView(Request $request, IssueDocument|InventoryAdjustmentDocument $document, string $kind, GlobalSettings $settings, ?array $readiness = null): View
    {
        $queueStatus = $request->query('queue_status', $document->status);
        abort_unless(in_array($queueStatus, ['DRAFT', 'APPROVED'], true), 404);
        $permissionPrefix = $kind === 'issue' ? 'wms.issues' : 'wms.inventory-adjustments';
        $queueRoute = $kind === 'issue' ? 'production.document-queues.material-issues.index' : 'production.document-queues.finished-receipts.index';
        return view('Production::document-queues.show', [
            'document' => $document,
            'kind' => $kind,
            'queueStatus' => $queueStatus,
            'queueRoute' => $queueRoute,
            'backUrl' => route($queueRoute, ['status' => $queueStatus]),
            'statusLabel' => $this->statusLabel($document->status),
            'statusClass' => self::STATUS_CLASSES[$document->status] ?? 'neutral',
            'dateFormat' => (string) ($settings->value('date_format') ?: 'd/m/Y'),
            'canApprove' => $document->status === 'DRAFT' && $request->user()->hasPermission($permissionPrefix.'.approve'),
            'canPost' => $document->status === 'APPROVED' && $request->user()->hasPermission($permissionPrefix.'.post') && ($kind !== 'receipt' || config('erp.inventory.manual_production_receipt_posting_enabled', false)),
            'postReadiness' => $readiness,
            'approveUrl' => route($kind === 'issue' ? 'production.document-queues.material-issues.approve' : 'production.document-queues.finished-receipts.approve', $document),
            'postUrl' => route($kind === 'issue' ? 'production.document-queues.material-issues.post' : 'production.document-queues.finished-receipts.post', $document),
        ]);
    }

    private function queueStatus(Request $request): string
    {
        $status = strtoupper((string) $request->input('status', 'DRAFT'));
        abort_unless(in_array($status, ['DRAFT', 'APPROVED'], true), 404);

        return $status;
    }

    private function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? $status;
    }

    private function filterItemSummary($query, string $keyword): void
    {
        foreach (array_filter(array_map('trim', explode(',', $keyword))) as $term) {
            $parts = explode(' · ', $term, 2);
            $query->whereHas('lines.item', function ($items) use ($parts, $term): void {
                $items->where('code', 'like', '%'.trim($parts[0]).'%');
                if (isset($parts[1])) {
                    $items->where('name', 'like', '%'.trim($parts[1]).'%');
                } else {
                    $items->orWhere('name', 'like', '%'.$term.'%');
                }
            });
        }
    }

    private function filterStatusLabel($query, string $label, string $keyword): void
    {
        if (! Str::contains(Str::lower($label), Str::lower($keyword))) {
            $query->whereRaw('1 = 0');
        }
    }

    private function filterDate($query, string $format, string $keyword): void
    {
        try {
            $date = Carbon::createFromFormat('!'.$format, trim($keyword));
        } catch (\Throwable) {
            $date = false;
        }

        if ($date && $date->format($format) === trim($keyword)) {
            $query->whereDate('document_date', $date->toDateString());
        } else {
            $query->whereRaw('1 = 0');
        }
    }

    private function sourceIssue(Request $request, InventoryAdjustmentDocument $document): ?IssueDocument
    {
        if (! Schema::hasColumn('wms_inventory_adjustment_documents', 'source_issue_id')) {
            return null;
        }

        return IssueDocument::query()
            ->where('id', $document->source_issue_id)
            ->where('branch_id', $request->attributes->get('selectedBranch')->id)
            ->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)
            ->where('issue_type', 'PRODUCTION')->where('status', 'POSTED')
            ->first(['id', 'document_number']);
    }

    private function scopeIssue(Request $request, IssueDocument $document): void
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $branch = $request->attributes->get('selectedBranch');
        abort_unless((int) $document->warehouse_id === (int) $warehouse->id && (int) $document->branch_id === (int) $branch->id && $document->issue_type === 'PRODUCTION', 404);
    }

    private function scopeReceipt(Request $request, InventoryAdjustmentDocument $document): void
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $branch = $request->attributes->get('selectedBranch');
        abort_unless((int) $document->warehouse_id === (int) $warehouse->id && (int) $document->branch_id === (int) $branch->id && $document->document_context === 'PRODUCTION_RECEIPT', 404);
    }

    private function success(string $message): JsonResponse
    {
        return response()->json(['status' => true, 'msg' => $message, 'redirect' => route('production.index')]);
    }
}
