<?php

namespace App\Modules\Wms\Controllers;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\CostRevaluationRun;
use App\Modules\Wms\Models\CostRevaluationBatch;
use App\Modules\Wms\Models\IssueDocument;
use App\Modules\Wms\Models\IssueReturn;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\StockBalance;
use App\Modules\Wms\Models\StockCostLayer;
use App\Modules\Wms\Jobs\ApplyCostRevaluation;
use App\Modules\Wms\Services\CostLineageExplorer;
use App\Modules\Wms\Services\CostPropagationManualTriggerService;
use App\Modules\Wms\Services\CostPropagationScopeTriggerService;
use Carbon\CarbonImmutable;
use App\Modules\Wms\Services\CostRevaluationApprovalService;
use App\Modules\Wms\Services\CostRevaluationJournalPostingService;
use App\Modules\Wms\Services\CostRevaluationPreflightService;
use App\Modules\Wms\Services\CostRevaluationReconciliationService;
use App\Modules\Wms\Services\CostRevaluationRecoveryService;
use App\Modules\Wms\Services\CostShadowCalculationService;
use App\Modules\Wms\Services\InventoryCostAllocationService;
use App\Modules\Wms\Services\InventoryPostingPreflightService;
use App\Modules\Wms\Services\InventoryReconciliationService;
use App\Modules\Wms\Services\LegacyIssueAccountingProofRecoveryService;
use App\Modules\Wms\Services\RecostQueueHealth;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class StockValuationController extends Controller
{
    public function legacyAccountingProof(Request $request, LegacyIssueAccountingProofRecoveryService $recovery): View
    {
        $values = $request->validate([
            'document_type' => ['nullable', 'in:ISSUE,ISSUE_RETURN'],
            'document_id' => ['nullable', 'integer', 'min:1'],
            'posting_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $document = null;
        $preview = null;
        $documentType = $values['document_type'] ?? null;
        $documentId = $values['document_id'] ?? null;
        $postingDate = $values['posting_date'] ?? null;
        if ($documentType && $documentId && $postingDate) {
            $document = $documentType === 'ISSUE'
                ? IssueDocument::query()->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->findOrFail($documentId)
                : IssueReturn::query()->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->findOrFail($documentId);
            $preview = $recovery->preview($document, $request->attributes->get('selectedWarehouse'), $postingDate);
        }

        return view('Wms::stock-valuation.legacy-accounting-proof', [
            'document' => $document,
            'preview' => $preview,
            'values' => $values,
        ]);
    }

    public function legacyAccountingProofRecover(Request $request, LegacyIssueAccountingProofRecoveryService $recovery, AuditLogger $audit)
    {
        $values = $request->validate([
            'document_type' => ['required', 'in:ISSUE,ISSUE_RETURN'],
            'document_id' => ['required', 'integer', 'min:1'],
            'posting_date' => ['required', 'date_format:Y-m-d'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $document = $values['document_type'] === 'ISSUE'
            ? IssueDocument::query()->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->findOrFail($values['document_id'])
            : IssueReturn::query()->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)->findOrFail($values['document_id']);
        $result = $recovery->recover($document, $request->attributes->get('selectedWarehouse'), $request->user(), $values['posting_date'], $values['reason']);
        $audit->record('wms.legacy_issue_accounting_proof.recovered', $document, [], $result, $request->user(), $request);

        return redirect()->route('wms.stock-valuation.legacy-accounting-proof', [
            'document_type' => $values['document_type'], 'document_id' => $values['document_id'], 'posting_date' => $values['posting_date'],
        ])->with('success', 'กู้คืน Journal proof สำเร็จแล้ว #'.$result['journal_id']);
    }

    public function index(Request $request): View
    {
        return view('Wms::stock-valuation.index', ['warehouse' => $request->attributes->get('selectedWarehouse'), 'warehouses' => $this->warehouses($request)]);
    }

    public function lineage(Request $request): View
    {
        return view('Wms::stock-valuation.lineage', [
            'warehouse' => $request->attributes->get('selectedWarehouse'),
            'warehouses' => $this->warehouses($request),
        ]);
    }

    public function lineageData(Request $request, CostLineageExplorer $explorer): JsonResponse
    {
        $values = $request->validate(['item_id' => ['nullable', 'integer', 'min:1']]);

        return response()->json($explorer->snapshot(
            (int) $request->attributes->get('selectedWarehouse')->id,
            (int) ($values['item_id'] ?? 0) ?: null,
        ));
    }

    public function shadowCalculation(): View
    {
        return view('Wms::stock-valuation.shadow-calculation');
    }

    public function shadowCalculationData(Request $request, CostShadowCalculationService $shadow): JsonResponse
    {
        $values = $request->validate([
            'allocation_id' => ['required', 'integer', 'min:1'],
            'proposed_unit_cost' => ['required', 'regex:/^\d+(?:\.\d{1,8})?$/'],
        ]);

        return response()->json($shadow->calculate((int) $values['allocation_id'], (string) $values['proposed_unit_cost']));
    }

    public function shadowAllocationOptions(Request $request, CostShadowCalculationService $shadow): JsonResponse
    {
        return response()->json([
            'results' => $shadow->allocationOptions(
                (int) $request->attributes->get('selectedWarehouse')->id,
                trim((string) $request->input('q')) ?: null,
            ),
        ]);
    }

    public function manualTrigger(Request $request, CostPropagationManualTriggerService $manual, CostPropagationScopeTriggerService $scopes): View
    {
        return view('Wms::stock-valuation.manual-trigger', [
            'documentTypes' => $manual->types(),
            'scopeBranches' => $scopes->branches($request->user()),
            'selectedBranchId' => (int) $request->attributes->get('selectedBranch')->id,
        ]);
    }

    public function emergencyRebuild(Request $request, CostPropagationScopeTriggerService $scopes): View
    {
        $branchId = (int) $request->attributes->get('selectedBranch')->id;

        return view('Wms::stock-valuation.emergency-rebuild', [
            'emergency' => $scopes->emergencyPreview($request->user(), $branchId),
        ]);
    }

    public function emergencyRebuildDispatch(Request $request, CostPropagationScopeTriggerService $scopes)
    {
        $values = $request->validate([
            'typed_confirmation' => ['required', 'string', 'max:100'],
            'reason' => ['required', 'string', 'min:20', 'max:1000'],
        ]);
        $batch = $scopes->emergencyTrigger(
            $request->user(),
            (int) $request->attributes->get('selectedBranch')->id,
            $values['typed_confirmation'],
            $values['reason'],
            $request->ip(),
            $request->userAgent(),
        );

        return redirect()->route('wms.stock-valuation.revaluation.index')->with('success', 'สร้าง Emergency rebuild Batch #'.$batch->id.' และส่งงานแบบ bounded แล้ว');
    }

    public function manualTriggerOptions(Request $request, CostPropagationManualTriggerService $manual): JsonResponse
    {
        $values = $request->validate([
            'document_type' => ['required', 'string', 'max:50'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(['results' => $manual->options(
            $values['document_type'],
            (int) $request->attributes->get('selectedWarehouse')->id,
            $values['q'] ?? null,
        )]);
    }

    public function manualTriggerPreview(Request $request, CostPropagationManualTriggerService $manual): JsonResponse
    {
        $values = $request->validate([
            'document_type' => ['required', 'string', 'max:50'],
            'document_id' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($manual->preview(
            $values['document_type'],
            (int) $values['document_id'],
            (int) $request->attributes->get('selectedWarehouse')->id,
        ));
    }

    public function manualTriggerScopeItems(Request $request, CostPropagationScopeTriggerService $scopes): JsonResponse
    {
        $values = $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
            'warehouse_ids' => ['required', 'array', 'min:1', 'max:100'],
            'warehouse_ids.*' => ['integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json(['results' => $scopes->itemOptions(
            $request->user(),
            (int) $values['branch_id'],
            $values['warehouse_ids'],
            $values['q'] ?? null,
        )]);
    }

    public function manualTriggerScopePreview(Request $request, CostPropagationScopeTriggerService $scopes): JsonResponse
    {
        $values = $this->scopeTriggerValues($request);

        return response()->json($scopes->preview(
            $request->user(), (int) $values['branch_id'], $values['warehouse_mode'], $values['warehouse_ids'] ?? [],
            $values['item_mode'], $values['item_ids'] ?? [], $values['start_date'],
        ));
    }

    public function manualTriggerDispatch(Request $request, CostPropagationManualTriggerService $manual, CostPropagationScopeTriggerService $scopes)
    {
        if (strtoupper((string) $request->input('trigger_mode')) === 'SCOPE') {
            $values = $this->scopeTriggerValues($request, true);
            $batch = $scopes->trigger(
                $request->user(), (int) $values['branch_id'], $values['warehouse_mode'], $values['warehouse_ids'] ?? [],
                $values['item_mode'], $values['item_ids'] ?? [], $values['start_date'], $values['scope_reason'],
                $request->ip(), $request->userAgent(),
            );

            return redirect()->route('wms.stock-valuation.revaluation.index')
                ->with('success', "สร้าง Scope Batch #{$batch->id} และส่ง {$batch->expected_partitions} partitions เข้าคิวแล้ว");
        }
        $values = $request->validate([
            'document_type' => ['required', 'string', 'max:50'],
            'document_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);
        $batch = $manual->trigger(
            $values['document_type'],
            (int) $values['document_id'],
            (int) $request->attributes->get('selectedWarehouse')->id,
            $request->user(),
            $values['reason'],
            $request->ip(),
            $request->userAgent(),
        );
        $run = $batch->runs->first();

        return $batch->runs->count() === 1
            ? redirect()->route('wms.stock-valuation.revaluation.show', $run)->with('success', 'ส่งเอกสารเข้าคิว Revaluation แล้ว')
            : redirect()->route('wms.stock-valuation.revaluation.index')->with('success', "ส่งเอกสารเข้าคิว {$batch->expected_partitions} partitions แล้ว");
    }

    private function scopeTriggerValues(Request $request, bool $withReason = false): array
    {
        return $request->validate([
            'branch_id' => ['required', 'integer', 'min:1'],
            'warehouse_mode' => ['required', 'in:ALL,SELECTED'],
            'warehouse_ids' => ['nullable', 'array', 'max:100'],
            'warehouse_ids.*' => ['integer', 'min:1'],
            'item_mode' => ['required', 'in:ALL,SELECTED'],
            'item_ids' => ['nullable', 'array', 'max:500'],
            'item_ids.*' => ['integer', 'min:1'],
            'start_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'scope_reason' => $withReason ? ['required', 'string', 'min:10', 'max:1000'] : ['nullable'],
        ]);
    }

    public function revaluationQueue(Request $request): View
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $scopeBatches = CostRevaluationBatch::query()
            ->where('source_document_type', 'MANUAL_SCOPE')
            ->whereIn('status', ['PLANNING', 'QUEUED', 'CALCULATING'])
            ->whereJsonContains('trigger_snapshot->scope->warehouse_ids', $warehouseId)
            ->latest('id')
            ->limit(25)
            ->get(['id', 'status', 'expected_partitions', 'resolved_root_lines', 'completed_partitions', 'failed_partitions', 'created_at']);

        return view('Wms::stock-valuation.revaluation-queue', compact('scopeBatches'));
    }

    public function revaluationQueueData(Request $request): JsonResponse
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        $query = CostRevaluationRun::query()
            ->with('rootAllocation.movement')
            ->whereHas('rootAllocation', fn ($allocation) => $allocation->where('warehouse_id', $warehouseId))
            ->select('wms_cost_revaluation_runs.*');

        $status = $request->string('status')->toString();
        if (in_array($status, [
            'QUEUED', 'CALCULATING', 'WAITING_CONTINUATION', 'PENDING_APPROVAL', 'APPROVED',
            'REJECTED', 'APPLYING', 'APPLIED', 'STOCK_PROJECTED', 'JOURNAL_POSTED', 'GL_POSTED',
            'COMPLETED', 'FAILED_RETRYABLE', 'REQUIRES_REVIEW', 'LIMIT_REACHED', 'CANCELLED',
        ], true)) {
            $query->where('status', $status);
        }

        foreach (['date_from' => '>=', 'date_to' => '<'] as $field => $operator) {
            $value = $request->date($field);
            if (! $value) {
                continue;
            }

            $date = CarbonImmutable::createFromFormat('Y-m-d', $value, 'Asia/Bangkok');
            $boundary = $field === 'date_from' ? $date->startOfDay() : $date->addDay()->startOfDay();
            $query->where('wms_cost_revaluation_runs.created_at', $operator, $boundary->utc());
        }

        return DataTables::eloquent($query)
            ->addColumn('allocation_label', fn ($row) => '#'.$row->root_allocation_id)
            ->addColumn('source_label', fn ($row) => $row->rootAllocation?->movement?->source_reference ?: $row->rootAllocation?->movement?->source_type ?: '-')
            ->addColumn('created_at_label', function ($row): string {
                $created = $row->created_at?->timezone('Asia/Bangkok');

                return $created ? $created->format('d/m/Y H:i').' · '.$created->locale('th')->diffForHumans().' (UTC+7)' : '-';
            })
            ->addColumn('status_label', fn ($row) => match ($row->status) {
                'PENDING_APPROVAL' => '<span class="badge app-status-warning">รออนุมัติ</span>',
                'APPROVED' => '<span class="badge app-status-info">อนุมัติแล้ว</span>',
                'REJECTED' => '<span class="badge app-status-danger">ปฏิเสธ</span>',
                'APPLIED', 'STOCK_PROJECTED' => '<span class="badge app-status-success">ปรับ Stock แล้ว</span>',
                'GL_POSTED', 'JOURNAL_POSTED' => '<span class="badge app-status-success">ลง Journal แล้ว</span>',
                'COMPLETED' => '<span class="badge app-status-success">เสร็จสมบูรณ์</span>',
                'CALCULATING', 'APPLYING' => '<span class="badge app-status-info">กำลังประมวลผล</span>',
                'WAITING_CONTINUATION', 'QUEUED' => '<span class="badge app-status-warning">รอประมวลผลต่อ</span>',
                'FAILED_RETRYABLE', 'REQUIRES_REVIEW', 'LIMIT_REACHED' => '<span class="badge app-status-danger">ต้องตรวจสอบ</span>',
                'CANCELLED' => '<span class="badge app-status-neutral">ยกเลิกแล้ว</span>',
                default => '<span class="badge app-status-neutral">'.e($row->status).'</span>',
            })
            ->addColumn('action', fn ($row) => '<a class="btn btn-sm btn-app-soft" href="'.route('wms.stock-valuation.revaluation.show', $row).'" title="เปิดรายละเอียด">เปิดรายละเอียด</a>')
            ->rawColumns(['status_label', 'action'])
            ->toJson();
    }

    public function revaluationShow(Request $request, CostRevaluationRun $run, CostRevaluationPreflightService $preflight, CostRevaluationReconciliationService $reconciliation): View
    {
        $warehouseId = (int) $request->attributes->get('selectedWarehouse')->id;
        abort_unless((int) $run->rootAllocation?->warehouse_id === $warehouseId, 404);

        return view('Wms::stock-valuation.revaluation-show', [
            'run' => $run->load(['batch', 'rootAllocation.movement', 'deltas.allocation']),
            'preflight' => $preflight->check($run),
            'reconciliation' => $reconciliation->check($run),
            'history' => AuditLog::query()->with('user:id,name')->where('subject_type', $run->getMorphClass())->where('subject_id', $run->id)->latest('created_at')->latest('id')->get(),
        ]);
    }

    public function revaluationApprove(Request $request, CostRevaluationRun $run, CostRevaluationApprovalService $approval)
    {
        $this->assertRevaluationScope($request, $run);
        $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $approval->approve($run, $request->user(), $request->string('reason')->toString());

        return redirect()->route('wms.stock-valuation.revaluation.show', $run)->with('success', 'อนุมัติ Revaluation Run แล้ว แต่ยังไม่ Apply จนกว่า feature gate จะเปิด');
    }

    public function revaluationReject(Request $request, CostRevaluationRun $run, CostRevaluationApprovalService $approval)
    {
        $this->assertRevaluationScope($request, $run);
        $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $approval->reject($run, $request->user(), $request->string('reason')->toString());

        return redirect()->route('wms.stock-valuation.revaluation.show', $run)->with('success', 'ปฏิเสธ Revaluation Run แล้ว');
    }

    public function revaluationApply(Request $request, CostRevaluationRun $run, CostRevaluationPreflightService $preflight)
    {
        $this->assertRevaluationScope($request, $run);
        if (! config('erp.inventory.revaluation_apply_enabled', false)) {
            return redirect()->route('wms.stock-valuation.revaluation.show', $run)
                ->withErrors(['revaluation' => 'Cost Revaluation Apply feature gate ยังปิดอยู่']);
        }
        $result = $preflight->check($run);
        if (! $result['ready']) {
            return redirect()->route('wms.stock-valuation.revaluation.show', $run)
                ->withErrors(['preflight' => 'Preflight ไม่ผ่าน: '.implode(', ', $result['blockers'])]);
        }
        ApplyCostRevaluation::dispatch($run->id)->afterCommit();

        return redirect()->route('wms.stock-valuation.revaluation.show', $run)
            ->with('success', 'ส่ง Revaluation Run เข้า Apply Queue แล้ว');
    }

    public function revaluationPostJournal(Request $request, CostRevaluationRun $run, CostRevaluationJournalPostingService $posting)
    {
        $this->assertRevaluationScope($request, $run);
        $posting->post($run, $request->user());

        return redirect()->route('wms.stock-valuation.revaluation.show', $run)->with('success', 'ลงบัญชี Revaluation สำเร็จ');
    }

    public function revaluationComplete(Request $request, CostRevaluationRun $run, CostRevaluationReconciliationService $reconciliation)
    {
        $this->assertRevaluationScope($request, $run);
        $reconciliation->complete($run);

        return redirect()->route('wms.stock-valuation.revaluation.show', $run)->with('success', 'Revaluation Run ผ่านการกระทบยอดและปิดรายการแล้ว');
    }

    public function revaluationResume(Request $request, CostRevaluationRun $run, CostRevaluationRecoveryService $recovery)
    {
        $this->assertRevaluationScope($request, $run);
        $values = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $recovery->resume($run, $request->user(), $values['reason']);

        return redirect()->route('wms.stock-valuation.revaluation.show', $run)->with('success', 'ส่ง Revaluation Run กลับเข้าคิวแล้ว');
    }

    public function revaluationCancel(Request $request, CostRevaluationRun $run, CostRevaluationRecoveryService $recovery)
    {
        $this->assertRevaluationScope($request, $run);
        $values = $request->validate([
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
            'confirmation' => ['required', 'in:CANCEL RUN '.$run->id],
        ]);
        $recovery->cancel($run, $request->user(), $values['reason']);

        return redirect()->route('wms.stock-valuation.revaluation.show', $run)->with('success', 'ยกเลิก Revaluation Run แล้ว');
    }

    private function assertRevaluationScope(Request $request, CostRevaluationRun $run): void
    {
        abort_unless((int) $run->rootAllocation?->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
    }

    public function show(Request $request, Item $item): View
    {
        abort_unless($item->is_active, 404);

        return view('Wms::stock-valuation.show', [
            'warehouse' => $request->attributes->get('selectedWarehouse'),
            'item' => $item,
        ]);
    }

    public function data(Request $request, InventoryCostAllocationService $costing): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $values = $request->validate([
            'as_of' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'item_id' => ['nullable', 'integer', 'min:1'],
            'stock_status' => ['nullable', 'in:in_stock,out_of_stock,negative'],
        ]);
        $asOf = $values['as_of'] ?? now('Asia/Bangkok')->toDateString();
        $itemId = (int) ($values['item_id'] ?? 0) ?: null;
        $valuation = $costing->historicalValuationQuery($asOf, (int) $warehouse?->id, $itemId)->toBase();
        $totals = DB::query()->fromSub($valuation, 'valuation_totals')
            ->selectRaw('COALESCE(SUM(final_quantity), 0) AS on_hand')
            ->selectRaw('COALESCE(SUM(final_value), 0) AS inventory_value')
            ->first();
        $stockTotals = StockBalance::query()->where('warehouse_id', $warehouse?->id)
            ->selectRaw('COALESCE(SUM(reserved), 0) AS reserved')
            ->selectRaw('COALESCE(SUM(available), 0) AS available')
            ->first();
        $totalQuantity = BigDecimal::of((string) ($totals?->on_hand ?? '0'));
        $totalValue = BigDecimal::of((string) ($totals?->inventory_value ?? '0'));
        $summary = [
            'on_hand' => WmsDecimal::format($totalQuantity->__toString()),
            'reserved' => WmsDecimal::format($stockTotals?->reserved ?? '0'),
            'available' => WmsDecimal::format($stockTotals?->available ?? '0'),
            'average_unit_cost' => WmsDecimal::format(($totalQuantity->isZero() ? BigDecimal::zero() : $totalValue->dividedBy($totalQuantity, 8, RoundingMode::HALF_UP))->__toString()),
            'inventory_value' => WmsDecimal::format(($totalQuantity->isZero() ? BigDecimal::zero() : $totalValue)->__toString()),
        ];
        $branch = $request->attributes->get('selectedBranch');
        $query = Item::query()->leftJoinSub($valuation, 'valuation', fn ($join) => $join->on('valuation.item_id', '=', 'wms_items.id'))
            ->leftJoin('wms_uoms', 'wms_uoms.id', '=', 'wms_items.base_uom_id')
            ->where('wms_items.is_active', true)
            ->selectRaw('wms_items.id, wms_items.code, wms_items.name, wms_uoms.code AS uom_code, COALESCE(valuation.final_quantity, 0) AS on_hand, CASE WHEN COALESCE(valuation.final_quantity, 0) = 0 THEN 0 ELSE COALESCE(valuation.final_value, 0) / valuation.final_quantity END AS average_unit_cost, CASE WHEN COALESCE(valuation.final_quantity, 0) = 0 THEN 0 ELSE COALESCE(valuation.final_value, 0) END AS inventory_value');
        if (($values['stock_status'] ?? null) === 'in_stock') {
            $query->whereRaw('COALESCE(valuation.final_quantity, 0) > 0');
        } elseif (($values['stock_status'] ?? null) === 'out_of_stock') {
            $query->whereRaw('COALESCE(valuation.final_quantity, 0) = 0');
        } elseif (($values['stock_status'] ?? null) === 'negative') {
            $query->whereRaw('COALESCE(valuation.final_quantity, 0) < 0');
        }

        return DataTables::eloquent($query)
            ->addColumn('item_label', fn ($row) => trim($row->item_code.' · '.$row->item_name))
            ->addColumn('uom_label', fn ($row) => $row->uom_code ?: '-')
            ->addColumn('detail_url', fn ($row) => route('wms.stock.show', $row->id).'?'.http_build_query(['branch_id' => $branch?->id, 'warehouse_id' => $warehouse?->id, 'item_id' => $row->id]))
            ->editColumn('on_hand', fn ($row) => WmsDecimal::format($row->on_hand))
            ->editColumn('average_unit_cost', fn ($row) => WmsDecimal::format($row->average_unit_cost))
            ->editColumn('inventory_value', fn ($row) => WmsDecimal::format($row->inventory_value))
            ->with(['balance' => $summary])
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
