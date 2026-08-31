<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\CommissionPaymentBatch;
use App\Modules\Pos\Models\CommissionRecord;
use App\Modules\Pos\Services\CommissionPaymentBatchService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class CommissionPaymentBatchController extends Controller
{
    public function data(Request $request): JsonResponse
    {
        $batches = CommissionPaymentBatch::query()->withCount('lines')->with(['lines.commissionRecord', 'paymentRequests.voucher.settlement'])->where('branch_id', $request->attributes->get('selectedBranch')->id);

        return DataTables::eloquent($batches)->order(fn (Builder $query) => $query->orderByDesc('id'))
            ->addColumn('period_label', fn (CommissionPaymentBatch $batch) => $batch->period_from->format('d/m/Y').' - '.$batch->period_to->format('d/m/Y'))
            ->addColumn('status_label', fn (CommissionPaymentBatch $batch) => match ($batch->status) {
                'SUBMITTED' => 'ส่งให้การเงินแล้ว',
                'VERIFIED' => 'ฝ่ายการเงินตรวจสอบแล้ว',
                'CANCELLED' => 'ยกเลิกแล้ว',
                default => 'ร่าง',
            })
            ->addColumn('request_summary', fn (CommissionPaymentBatch $batch) => $this->requestSummary($batch))
            ->addColumn('payment_summary', fn (CommissionPaymentBatch $batch) => $this->paymentSummary($batch))
            ->addColumn('show_url', fn (CommissionPaymentBatch $batch) => route('pos.sales-commission-payment-batches.show', $batch))
            ->addColumn('history_url', fn (CommissionPaymentBatch $batch) => route('pos.sales-commission-payment-batches.history', $batch))
            ->toJson();
    }

    public function create(Request $request): View
    {
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $recipients = User::query()->where('is_active', true)->whereIn('id', CommissionRecord::query()->where('branch_id', $branchId)->where('status', 'APPROVED')->select('recipient_user_id'))->orderBy('name')->get(['id', 'name']);

        return view('Pos::sales-commission-payment-batches.create', compact('recipients'));
    }

    public function store(Request $request, CommissionPaymentBatchService $service, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['period_from' => ['required', 'date_format:Y-m-d'], 'period_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_from'], 'selection_mode' => ['required', 'in:ALL,SELECTED'], 'recipient_ids' => ['array'], 'recipient_ids.*' => ['integer']]);
        $recipientIds = $data['selection_mode'] === 'SELECTED' ? array_values($data['recipient_ids'] ?? []) : [];
        if ($data['selection_mode'] === 'SELECTED' && $recipientIds === []) {
            return response()->json(['message' => 'กรุณาเลือกพนักงานอย่างน้อย 1 คน'], 422);
        }
        $batch = $service->create((int) $request->attributes->get('selectedBranch')->id, $data['period_from'], $data['period_to'], $recipientIds, $request->user());
        $audit->record('pos.commission_payment_batch.created', $batch, [], $batch->only(['document_number', 'period_from', 'period_to', 'total_amount', 'status']), $request->user(), $request);

        return response()->json(['status' => true, 'redirect' => route('pos.sales-commission-payment-batches.show', $batch)]);
    }

    public function show(Request $request, CommissionPaymentBatch $commissionPaymentBatch): View
    {
        $batch = CommissionPaymentBatch::query()->with('lines.commissionRecord.recipient', 'lines.commissionRecord.physicalSale')->findOrFail($commissionPaymentBatch->id);
        $this->scope($request, $batch);
        $recipientDetails = $batch->lines->groupBy(fn ($line) => $line->commissionRecord->recipient_user_id);
        $recipientTotals = $recipientDetails->map(fn ($lines) => ['name' => $lines->first()->commissionRecord->recipient?->name ?? '—', 'amount' => $lines->sum(fn ($line) => (float) $line->amount), 'lines' => $lines->count()]);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $batch->getMorphClass())->where('subject_id', $batch->id)->latest('created_at')->latest('id')->get();

        return view('Pos::sales-commission-payment-batches.show', compact('batch', 'recipientTotals', 'recipientDetails', 'history'));
    }

    public function history(Request $request, CommissionPaymentBatch $commissionPaymentBatch): JsonResponse
    {
        $this->scope($request, $commissionPaymentBatch);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $commissionPaymentBatch->getMorphClass())->where('subject_id', $commissionPaymentBatch->id)->latest('created_at')->latest('id')->get()
            ->map(fn (AuditLog $log) => ['at' => $log->created_at?->format('d/m/Y H:i'), 'action' => $log->action, 'actor' => $log->user?->name ?? 'ระบบ', 'reason' => $log->reason]);

        return response()->json(['document_number' => $commissionPaymentBatch->document_number, 'history' => $history]);
    }

    public function submit(Request $request, CommissionPaymentBatch $commissionPaymentBatch, CommissionPaymentBatchService $service, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $commissionPaymentBatch);
        $batch = $service->submit($commissionPaymentBatch, $request->user());
        $audit->record('pos.commission_payment_batch.submitted', $batch, ['status' => 'DRAFT'], $batch->only(['status', 'submitted_by', 'submitted_at']), $request->user(), $request);

        return response()->json(['status' => true, 'redirect' => route('pos.sales-commission-payment-batches.show', $batch)]);
    }

    public function cancel(Request $request, CommissionPaymentBatch $commissionPaymentBatch, CommissionPaymentBatchService $service, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $commissionPaymentBatch);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $before = $commissionPaymentBatch->only(['status']);
        $batch = $service->cancelDraft($commissionPaymentBatch, $request->user(), $data['reason']);
        $audit->record('pos.commission_payment_batch.cancelled', $batch, $before, ['status' => $batch->status, 'reason' => $data['reason']], $request->user(), $request);

        return response()->json(['status' => true, 'redirect' => route('pos.sales-commissions.index')]);
    }

    private function scope(Request $request, CommissionPaymentBatch $batch): void
    {
        abort_unless((int) $batch->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
    }

    private function requestSummary(CommissionPaymentBatch $batch): array
    {
        $total = $batch->lines->pluck('commissionRecord.recipient_user_id')->filter()->unique()->count();
        $requests = $batch->paymentRequests;
        $approved = $requests->where('status', 'APPROVED')->count();
        $submitted = $requests->where('status', 'SUBMITTED')->count();
        $draft = $requests->where('status', 'DRAFT')->count();
        $cancelled = $requests->where('status', 'CANCELLED')->count();

        if ($approved === $total && $total) {
            return ['label' => "อนุมัติแล้ว {$approved}/{$total}", 'class' => 'app-status-success'];
        }
        if ($cancelled === $total && $total) {
            return ['label' => "ยกเลิกแล้ว {$cancelled}/{$total}", 'class' => 'app-status-danger'];
        }
        if ($submitted) {
            return ['label' => "รออนุมัติ {$submitted}/{$total}", 'class' => 'app-status-info'];
        }
        if ($draft) {
            return ['label' => "ฉบับร่าง {$draft}/{$total}", 'class' => 'app-status-neutral'];
        }

        return ['label' => $batch->status === 'VERIFIED' ? "ยังไม่สร้าง 0/{$total}" : '—', 'class' => 'app-status-neutral'];
    }

    private function paymentSummary(CommissionPaymentBatch $batch): array
    {
        $total = $batch->lines->pluck('commissionRecord.recipient_user_id')->filter()->unique()->count();
        $requests = $batch->paymentRequests;
        $paid = $requests->filter(fn ($request) => $request->voucher?->settlement?->status === 'POSTED')->count();
        $voided = $requests->filter(fn ($request) => $request->voucher?->status === 'VOID')->count();
        $processing = $requests->filter(fn ($request) => $request->voucher && $request->voucher->status !== 'VOID' && $request->voucher->settlement?->status !== 'POSTED')->count();
        $ready = $requests->filter(fn ($request) => $request->status === 'APPROVED' && ! $request->voucher)->count();

        if ($paid === $total && $total) {
            return ['label' => "จ่ายแล้ว {$paid}/{$total}", 'class' => 'app-status-success'];
        }
        if ($voided === $total && $total) {
            return ['label' => "ไม่จ่าย (ยกเลิกแล้ว) {$voided}/{$total}", 'class' => 'app-status-danger'];
        }
        if ($processing) {
            return ['label' => "อยู่ระหว่างจ่าย {$processing}/{$total}", 'class' => 'app-status-info'];
        }
        if ($ready) {
            return ['label' => "รอสร้างใบสำคัญจ่าย {$ready}/{$total}", 'class' => 'app-status-info'];
        }

        return ['label' => $batch->status === 'VERIFIED' ? "ยังไม่พร้อมจ่าย 0/{$total}" : '—', 'class' => 'app-status-neutral'];
    }
}
