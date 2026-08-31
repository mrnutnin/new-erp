<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\CommissionRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class SalesCommissionController extends Controller
{
    public function index(): View
    {
        return view('Pos::sales-commissions.index');
    }

    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'recipient_user_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['PENDING', 'APPROVED', 'REJECTED', 'PAID', 'REVERSED'])],
        ]);

        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $records = CommissionRecord::query()
            ->with(['plan', 'recipient', 'physicalSale', 'paymentBatchLines.batch.paymentRequests.voucher.settlement'])
            ->withExists(['paymentBatchLines as has_cancelled_payment_batch' => fn (Builder $query) => $query->whereHas('batch', fn (Builder $batch) => $batch->where('status', 'CANCELLED'))])
            ->where('branch_id', $branchId)->whereIn('warehouse_id', $warehouseIds)
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('calculated_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('calculated_at', '<=', $date))
            ->when($filters['recipient_user_id'] ?? null, fn (Builder $query, int $id) => $query->where('recipient_user_id', $id))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status));

        return DataTables::eloquent($records)
            ->order(fn (Builder $query) => $query->orderByDesc('calculated_at')->orderByDesc('id'))
            ->addColumn('calculated_at_label', fn (CommissionRecord $record) => $record->calculated_at?->format('d/m/Y') ?: '—')
            ->addColumn('recipient_label', fn (CommissionRecord $record) => $record->recipient?->name ?: '—')
            ->addColumn('plan_label', fn (CommissionRecord $record) => $record->plan ? $record->plan->code.' · '.$record->plan->name : '—')
            ->addColumn('sale_label', fn (CommissionRecord $record) => $record->physicalSale?->document_number ?: '—')
            ->addColumn('sale_url', fn (CommissionRecord $record) => $record->physical_sale_id && $request->user()->hasPermission('pos.physical-sales.view') ? route('pos.physical-sales.show', $record->physical_sale_id) : null)
            ->addColumn('basis_label', fn (CommissionRecord $record) => match ($record->plan?->basis) {
                'POSTED_SALE' => 'ยอดขายที่ Post',
                'COLLECTED_RECEIPT' => 'ยอดรับชำระ',
                'GROSS_PROFIT' => 'กำไรขั้นต้น',
                default => '—',
            })
            ->addColumn('status_label', fn (CommissionRecord $record) => match ($record->status) {
                'PENDING' => 'รออนุมัติ',
                'APPROVED' => 'อนุมัติแล้ว',
                'REJECTED' => 'ไม่อนุมัติ',
                'PAID' => 'จ่ายแล้ว',
                'REVERSED' => 'กลับรายการแล้ว',
                default => $record->status,
            })
            ->addColumn('payment_progress', fn (CommissionRecord $record) => $this->paymentProgress($record))
            ->addColumn('approve_url', fn (CommissionRecord $record) => $record->status === 'PENDING' && $request->user()->hasPermission('pos.sales-commissions.approve') ? route('pos.sales-commissions.approve', $record) : null)
            ->addColumn('reject_url', fn (CommissionRecord $record) => $record->status === 'PENDING' && $request->user()->hasPermission('pos.sales-commissions.approve') ? route('pos.sales-commissions.reject', $record) : null)
            ->addColumn('edit_status_url', fn (CommissionRecord $record) => $record->status === 'APPROVED' && $record->has_cancelled_payment_batch && $request->user()->hasPermission('pos.sales-commissions.approve') ? route('pos.sales-commissions.update-status', $record) : null)
            ->addColumn('history_url', fn (CommissionRecord $record) => route('pos.sales-commissions.history', $record))
            ->toJson();
    }

    public function recipientOptions(Request $request): JsonResponse
    {
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $term = trim((string) $request->input('q'));

        $users = User::query()
            ->where('is_active', true)
            ->whereIn('id', CommissionRecord::query()->where('branch_id', $branchId)->whereIn('warehouse_id', $warehouseIds)->select('recipient_user_id'))
            ->when($term !== '', fn (Builder $query) => $query->where('name', 'like', "%{$term}%"))
            ->orderBy('name')
            ->limit(50)
            ->get(['id', 'name']);

        return response()->json(['results' => $users->map(fn (User $user) => ['id' => $user->id, 'text' => $user->name])]);
    }

    private function paymentProgress(CommissionRecord $record): array
    {
        $lines = $record->paymentBatchLines->filter(fn ($line) => $line->batch)->sortByDesc(fn ($line) => $line->batch->id);
        $line = $lines->first(fn ($line) => $line->batch->status !== 'CANCELLED') ?? $lines->first();
        if (! $line) {
            return ['label' => 'ยังไม่สร้างชุดจ่าย', 'class' => 'app-status-neutral'];
        }

        $batch = $line->batch;
        if ($batch->status === 'CANCELLED') {
            return ['label' => "ยกเลิกชุดจ่าย {$batch->document_number}", 'class' => 'app-status-danger'];
        }
        if ($batch->status === 'DRAFT') {
            return ['label' => "อยู่ในชุดร่าง {$batch->document_number}", 'class' => 'app-status-neutral'];
        }
        if ($batch->status === 'SUBMITTED') {
            return ['label' => "ส่งให้การเงิน {$batch->document_number}", 'class' => 'app-status-info'];
        }

        $paymentRequest = $batch->paymentRequests->firstWhere('recipient_user_id', $record->recipient_user_id);
        if (! $paymentRequest) {
            return ['label' => 'ฝ่ายการเงินยังไม่สร้างใบขอจ่าย', 'class' => 'app-status-neutral'];
        }
        if ($paymentRequest->status === 'CANCELLED') {
            return ['label' => 'ไม่จ่าย (ยกเลิกใบขอจ่ายแล้ว)', 'class' => 'app-status-danger'];
        }
        if (! $paymentRequest->voucher) {
            return match ($paymentRequest->status) {
                'DRAFT' => ['label' => 'ใบขอจ่ายฉบับร่าง', 'class' => 'app-status-neutral'],
                'SUBMITTED' => ['label' => 'ใบขอจ่ายรออนุมัติ', 'class' => 'app-status-info'],
                default => ['label' => 'รอสร้างใบสำคัญจ่าย', 'class' => 'app-status-info'],
            };
        }
        if ($paymentRequest->voucher->status === 'VOID') {
            return ['label' => 'ไม่จ่าย (ยกเลิกใบสำคัญแล้ว)', 'class' => 'app-status-danger'];
        }
        if ($paymentRequest->voucher->settlement?->status === 'POSTED') {
            return ['label' => 'จ่ายแล้ว', 'class' => 'app-status-success'];
        }

        return ['label' => 'อยู่ระหว่างจ่าย', 'class' => 'app-status-info'];
    }

    public function approve(Request $request, CommissionRecord $commissionRecord, AuditLogger $audit): JsonResponse
    {
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $warehouseIds = $this->authorizedWarehouseIds($request);

        $record = DB::transaction(function () use ($commissionRecord, $branchId, $warehouseIds, $request, $audit): CommissionRecord {
            $record = CommissionRecord::query()->whereKey($commissionRecord->id)->where('branch_id', $branchId)->whereIn('warehouse_id', $warehouseIds)->lockForUpdate()->firstOrFail();
            abort_unless($record->status === 'PENDING', 422, 'รายการคอมมิชชั่นนี้ไม่ได้อยู่ในสถานะรออนุมัติ');

            $before = $record->only(['status', 'approved_by', 'approved_at']);
            $record->update(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
            $audit->record('pos.sales_commission.approved', $record, $before, $record->only(['status', 'approved_by', 'approved_at']), $request->user(), $request);

            return $record;
        });

        return response()->json(['status' => true, 'msg' => 'อนุมัติคอมมิชชั่นเรียบร้อย', 'commission_record_id' => $record->id]);
    }

    public function reject(Request $request, CommissionRecord $commissionRecord, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $warehouseIds = $this->authorizedWarehouseIds($request);

        $record = DB::transaction(function () use ($commissionRecord, $branchId, $warehouseIds, $request, $data, $audit): CommissionRecord {
            $record = CommissionRecord::query()->whereKey($commissionRecord->id)->where('branch_id', $branchId)->whereIn('warehouse_id', $warehouseIds)->lockForUpdate()->firstOrFail();
            abort_unless($record->status === 'PENDING', 422, 'รายการคอมมิชชั่นนี้ไม่ได้อยู่ในสถานะรออนุมัติ');

            $before = $record->only(['status', 'rejected_by', 'rejected_at', 'rejection_reason']);
            $record->update(['status' => 'REJECTED', 'rejected_by' => $request->user()->id, 'rejected_at' => now(), 'rejection_reason' => $data['reason']]);
            $audit->record('pos.sales_commission.rejected', $record, $before, $record->only(['status', 'rejected_by', 'rejected_at', 'rejection_reason']), $request->user(), $request);

            return $record;
        });

        return response()->json(['status' => true, 'msg' => 'ไม่อนุมัติคอมมิชชั่นเรียบร้อย', 'commission_record_id' => $record->id]);
    }

    public function updateStatus(Request $request, CommissionRecord $commissionRecord, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['PENDING', 'REJECTED'])], 'reason' => ['required_if:status,REJECTED', 'nullable', 'string', 'max:1000']]);
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $record = DB::transaction(function () use ($commissionRecord, $branchId, $warehouseIds, $request, $data, $audit): CommissionRecord {
            $record = CommissionRecord::query()->whereKey($commissionRecord->id)->where('branch_id', $branchId)->whereIn('warehouse_id', $warehouseIds)->lockForUpdate()->firstOrFail();
            abort_unless($record->status === 'APPROVED', 422, 'แก้ไขได้เฉพาะรายการที่อนุมัติแล้ว');
            abort_unless($record->paymentBatchLines()->whereHas('batch', fn (Builder $batch) => $batch->where('status', 'CANCELLED'))->exists(), 422, 'แก้ไขสถานะได้เมื่อชุดจ่ายถูกยกเลิกแล้วเท่านั้น');
            abort_if($record->paymentBatchLines()->whereHas('batch', fn (Builder $batch) => $batch->whereIn('status', ['DRAFT', 'SUBMITTED', 'VERIFIED']))->exists(), 422, 'ยังมีชุดจ่ายที่กำลังดำเนินการอยู่');

            $before = $record->only(['status', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason']);
            $values = $data['status'] === 'REJECTED'
                ? ['status' => 'REJECTED', 'rejected_by' => $request->user()->id, 'rejected_at' => now(), 'rejection_reason' => $data['reason']]
                : ['status' => 'PENDING', 'approved_by' => null, 'approved_at' => null, 'rejected_by' => null, 'rejected_at' => null, 'rejection_reason' => null];
            $record->update($values);
            $audit->record('pos.sales_commission.status_changed', $record, $before, [...$record->only(['status', 'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason']), 'reason' => $data['reason'] ?? null], $request->user(), $request);

            return $record;
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขสถานะคอมมิชชั่นเรียบร้อย', 'commission_record_id' => $record->id]);
    }

    public function history(Request $request, CommissionRecord $commissionRecord): JsonResponse
    {
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $record = CommissionRecord::query()->whereKey($commissionRecord->id)->where('branch_id', $branchId)->whereIn('warehouse_id', $warehouseIds)->firstOrFail();
        $history = AuditLog::query()->with('user:id,name')
            ->where('subject_type', $record->getMorphClass())->where('subject_id', $record->id)->latest('created_at')->latest('id')->get()
            ->map(fn (AuditLog $log) => ['at' => $log->created_at?->format('d/m/Y H:i'), 'action' => $log->action, 'actor' => $log->user?->name ?? 'ระบบ', 'reason' => $log->reason]);

        return response()->json(['record_label' => $record->physicalSale?->document_number ?? $record->source_id, 'history' => $history]);
    }

    /** @return list<int> */
    private function authorizedWarehouseIds(Request $request): array
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)
            ->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
    }
}
