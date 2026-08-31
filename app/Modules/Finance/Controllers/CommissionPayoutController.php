<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\CommissionPaymentRequest;
use App\Modules\Finance\Models\PaymentVoucher;
use App\Modules\Finance\Services\CommissionPaymentRequestService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\CommissionPaymentBatch;
use App\Modules\Pos\Models\CommissionPayoutBatch;
use App\Modules\Pos\Services\CommissionPaymentBatchService;
use App\Modules\Pos\Services\CommissionPayoutService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class CommissionPayoutController extends Controller
{
    public function index(): View
    {
        return view('Finance::commission-payouts.index');
    }

    public function data(Request $request): JsonResponse
    {
        $batches = CommissionPaymentBatch::query()->withCount('lines')->with(['lines.commissionRecord', 'paymentRequests.voucher.settlement'])->where('branch_id', $request->attributes->get('selectedBranch')->id)->whereIn('status', ['SUBMITTED', 'VERIFIED']);

        return DataTables::eloquent($batches)->order(fn (Builder $query) => $query->orderByDesc('id'))
            ->addColumn('period_label', fn (CommissionPaymentBatch $batch) => $batch->period_from->format('d/m/Y').' - '.$batch->period_to->format('d/m/Y'))
            ->addColumn('recipient_count', fn (CommissionPaymentBatch $batch) => $batch->lines->pluck('commissionRecord.recipient_user_id')->filter()->unique()->count())
            ->addColumn('request_summary', fn (CommissionPaymentBatch $batch) => $this->requestSummary($batch))
            ->addColumn('payment_summary', fn (CommissionPaymentBatch $batch) => $this->paymentSummary($batch))
            ->addColumn('show_url', fn (CommissionPaymentBatch $batch) => route('finance.commission-payouts.show', $batch))
            ->toJson();
    }

    public function show(Request $request, CommissionPaymentBatch $commissionPaymentBatch): View
    {
        $batch = CommissionPaymentBatch::query()->with('lines.commissionRecord.recipient')->findOrFail($commissionPaymentBatch->id);
        $this->scope($request, $batch);
        $paymentRequests = CommissionPaymentRequest::query()->where('payment_batch_id', $batch->id)->with('voucher.settlement')->get()->keyBy('recipient_user_id');
        $hasActivePaymentVoucher = $paymentRequests->contains(fn (CommissionPaymentRequest $paymentRequest) => $paymentRequest->voucher && $paymentRequest->voucher->status !== 'VOID');
        $hasOpenPaymentRequest = $paymentRequests->contains(fn (CommissionPaymentRequest $paymentRequest) => $paymentRequest->status !== 'CANCELLED');
        $cancelBlockedMessage = $hasActivePaymentVoucher ? 'ต้องยกเลิกใบสำคัญจ่ายทั้งหมดก่อน' : ($hasOpenPaymentRequest ? 'ต้องยกเลิกใบขอจ่ายคอมมิชชั่นทั้งหมดก่อน' : null);
        $recipientTotals = $batch->lines->groupBy(fn ($line) => $line->commissionRecord->recipient_user_id)->map(fn ($lines, $recipientId) => ['id' => $recipientId, 'name' => $lines->first()->commissionRecord->recipient?->name ?? '—', 'amount' => $lines->sum(fn ($line) => (float) $line->amount), 'lines' => $lines->count(), 'paymentRequest' => $paymentRequests->get($recipientId)]);
        $paidRecipientCount = $paymentRequests->filter(fn (CommissionPaymentRequest $paymentRequest) => $paymentRequest->voucher?->settlement?->status === 'POSTED')->count();
        $missingRequestCount = $recipientTotals->filter(fn (array $total) => ! $total['paymentRequest'])->count();
        $draftRequestCount = $paymentRequests->where('status', 'DRAFT')->count();
        $submittedRequestCount = $paymentRequests->where('status', 'SUBMITTED')->count();
        $approvedWithoutVoucherCount = $paymentRequests->filter(fn (CommissionPaymentRequest $paymentRequest) => $paymentRequest->status === 'APPROVED' && ! $paymentRequest->payment_voucher_id)->count();
        $hasCancelledPaymentRequest = $paymentRequests->contains(fn (CommissionPaymentRequest $paymentRequest) => $paymentRequest->status === 'CANCELLED');
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $batch->getMorphClass())->where('subject_id', $batch->id)->latest('created_at')->latest('id')->get();

        return view('Finance::commission-payouts.show', compact('batch', 'recipientTotals', 'paidRecipientCount', 'missingRequestCount', 'draftRequestCount', 'submittedRequestCount', 'approvedWithoutVoucherCount', 'hasCancelledPaymentRequest', 'hasActivePaymentVoucher', 'hasOpenPaymentRequest', 'cancelBlockedMessage', 'history'));
    }

    public function verify(Request $request, CommissionPaymentBatch $commissionPaymentBatch, CommissionPaymentBatchService $service, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $commissionPaymentBatch);
        $batch = $service->verify($commissionPaymentBatch);
        $audit->record('finance.commission_payment_batch.verified', $batch, ['status' => 'SUBMITTED'], ['status' => $batch->status], $request->user(), $request);

        return response()->json(['status' => true, 'redirect' => route('finance.commission-payouts.show', $batch)]);
    }

    public function cancel(Request $request, CommissionPaymentBatch $commissionPaymentBatch, CommissionPaymentBatchService $service, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $commissionPaymentBatch);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $before = $commissionPaymentBatch->only(['status']);
        $batch = $service->cancelForFinance($commissionPaymentBatch, $request->user(), $data['reason']);
        $audit->record('finance.commission_payment_batch.cancelled', $batch, $before, ['status' => $batch->status, 'reason' => $data['reason']], $request->user(), $request);

        return response()->json(['status' => true, 'redirect' => route('finance.commission-payouts.index')]);
    }

    public function createPayout(Request $request, CommissionPaymentBatch $commissionPaymentBatch): View
    {
        $this->scope($request, $commissionPaymentBatch);
        abort_unless($commissionPaymentBatch->status === 'VERIFIED', 422);
        $recipientId = (int) $request->integer('recipient_user_id');
        $recipient = $commissionPaymentBatch->lines()->with('commissionRecord.recipient')->get()->first(fn ($line) => (int) $line->commissionRecord->recipient_user_id === $recipientId)?->commissionRecord?->recipient;
        abort_unless($recipient, 404);
        $banks = BankAccount::query()->where('is_active', true)->whereHas('warehouse', fn (Builder $query) => $query->where('branch_id', $commissionPaymentBatch->branch_id))->orderBy('code')->get();

        return view('Finance::commission-payouts.create', compact('commissionPaymentBatch', 'recipient', 'banks'));
    }

    public function storePayout(Request $request, CommissionPaymentBatch $commissionPaymentBatch, CommissionPayoutService $service, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $commissionPaymentBatch);
        $data = $request->validate(['recipient_user_id' => ['required', 'integer'], 'bank_account_id' => ['required', 'integer'], 'document_date' => ['required', 'date_format:Y-m-d']]);
        $bank = BankAccount::query()->findOrFail($data['bank_account_id']);
        $payout = $service->createForPaymentBatch($commissionPaymentBatch, $data['recipient_user_id'], $bank, $data['document_date'], $request->user());
        $audit->record('finance.commission_payout.created', $payout, [], $payout->only(['document_number', 'payment_batch_id', 'recipient_user_id', 'total_amount', 'status']), $request->user(), $request);

        return response()->json(['status' => true, 'redirect' => route('finance.commission-payouts.payouts.show', [$commissionPaymentBatch, $payout])]);
    }

    public function showPayout(Request $request, CommissionPaymentBatch $commissionPaymentBatch, CommissionPayoutBatch $payout): View
    {
        $this->scope($request, $commissionPaymentBatch);
        abort_unless((int) $payout->payment_batch_id === (int) $commissionPaymentBatch->id, 404);
        $payout->load('recipient', 'bankAccount', 'lines.commissionRecord.physicalSale');

        return view('Finance::commission-payouts.payout-show', compact('commissionPaymentBatch', 'payout'));
    }

    public function postPayout(Request $request, CommissionPaymentBatch $commissionPaymentBatch, CommissionPayoutBatch $payout, CommissionPayoutService $service, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $commissionPaymentBatch);
        abort_unless((int) $payout->payment_batch_id === (int) $commissionPaymentBatch->id, 404);
        $posted = $service->post($payout, $request->user());
        $audit->record('finance.commission_payout.posted', $posted, ['status' => 'DRAFT'], $posted->only(['status', 'journal_entry_id', 'posted_by', 'posted_at']), $request->user(), $request);

        return response()->json(['status' => true, 'redirect' => route('finance.commission-payouts.payouts.show', [$commissionPaymentBatch, $posted])]);
    }

    public function createRequest(Request $request, CommissionPaymentBatch $commissionPaymentBatch, CommissionPaymentRequestService $service, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $commissionPaymentBatch);
        $data = $request->validate(['recipient_user_id' => ['required', 'integer']]);
        $paymentRequest = $service->create($commissionPaymentBatch, $data['recipient_user_id'], $request->user());
        $audit->record('finance.commission_payment_request.created', $paymentRequest, [], $paymentRequest->only(['document_number', 'payment_batch_id', 'recipient_user_id', 'supplier_party_id', 'amount', 'status']), $request->user(), $request);

        return response()->json(['status' => true, 'redirect' => route('finance.commission-requests.show', $paymentRequest)]);
    }

    public function createAllRequests(Request $request, CommissionPaymentBatch $commissionPaymentBatch, CommissionPaymentRequestService $service, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $commissionPaymentBatch);
        abort_unless($commissionPaymentBatch->status === 'VERIFIED', 422, 'ต้องตรวจสอบชุดจ่ายก่อนสร้างใบขอจ่าย');

        $recipientIds = $commissionPaymentBatch->lines()->with('commissionRecord')->get()
            ->map(fn ($line) => $line->commissionRecord?->recipient_user_id)->filter()->unique()->values();
        $existingIds = CommissionPaymentRequest::query()->where('payment_batch_id', $commissionPaymentBatch->id)->pluck('recipient_user_id');
        $missingIds = $recipientIds->diff($existingIds);
        abort_if($missingIds->isEmpty(), 422, 'สร้างใบขอจ่ายครบทุกพนักงานแล้ว');

        foreach ($missingIds as $recipientId) {
            $paymentRequest = $service->create($commissionPaymentBatch, (int) $recipientId, $request->user());
            $audit->record('finance.commission_payment_request.created', $paymentRequest, [], $paymentRequest->only(['document_number', 'payment_batch_id', 'recipient_user_id', 'supplier_party_id', 'amount', 'status']), $request->user(), $request);
        }

        return response()->json(['status' => true, 'msg' => 'สร้างใบขอจ่าย '.$missingIds->count().' รายการแล้ว', 'redirect' => route('finance.commission-payouts.show', $commissionPaymentBatch)]);
    }

    public function submitAllRequests(Request $request, CommissionPaymentBatch $commissionPaymentBatch, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $commissionPaymentBatch);
        $submittedCount = DB::transaction(function () use ($request, $commissionPaymentBatch, $audit): int {
            $batch = CommissionPaymentBatch::query()->lockForUpdate()->findOrFail($commissionPaymentBatch->id);
            abort_unless($batch->status === 'VERIFIED', 422, 'ชุดจ่ายนี้ยังไม่พร้อมส่งขออนุมัติ');
            $paymentRequests = CommissionPaymentRequest::query()->where('payment_batch_id', $batch->id)->lockForUpdate()->get();
            $recipientCount = $batch->lines()->with('commissionRecord')->get()->map(fn ($line) => $line->commissionRecord?->recipient_user_id)->filter()->unique()->count();
            abort_if($paymentRequests->count() !== $recipientCount || $paymentRequests->contains('status', 'CANCELLED'), 422, 'ต้องสร้างใบขอจ่ายให้ครบทุกพนักงาน และไม่มีเอกสารที่ยกเลิกก่อนส่งขออนุมัติทั้งชุด');

            $drafts = $paymentRequests->where('status', 'DRAFT');
            abort_if($drafts->isEmpty(), 422, 'ไม่มีใบขอจ่ายฉบับร่างให้ส่งขออนุมัติ');
            foreach ($drafts as $paymentRequest) {
                $paymentRequest->update(['status' => 'SUBMITTED', 'submitted_by' => $request->user()->id, 'submitted_at' => now()]);
                $audit->record('finance.commission_payment_request.submitted', $paymentRequest, ['status' => 'DRAFT'], $paymentRequest->only(['status', 'submitted_by', 'submitted_at']), $request->user(), $request);
            }

            return $drafts->count();
        });

        return response()->json(['status' => true, 'msg' => 'ส่งใบขอจ่าย '.$submittedCount.' รายการเพื่อขออนุมัติแล้ว', 'redirect' => route('finance.commission-payouts.show', $commissionPaymentBatch)]);
    }

    public function cancelAllRequests(Request $request, CommissionPaymentBatch $commissionPaymentBatch, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $commissionPaymentBatch);
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $cancelledCount = DB::transaction(function () use ($request, $commissionPaymentBatch, $data, $audit): int {
            $batch = CommissionPaymentBatch::query()->lockForUpdate()->findOrFail($commissionPaymentBatch->id);
            abort_unless($batch->status === 'VERIFIED', 422, 'ชุดจ่ายนี้ไม่สามารถยกเลิกใบขอจ่ายได้');
            $paymentRequests = CommissionPaymentRequest::query()->with('voucher')->where('payment_batch_id', $batch->id)->lockForUpdate()->get();
            abort_if($paymentRequests->contains(fn (CommissionPaymentRequest $paymentRequest) => $paymentRequest->voucher && $paymentRequest->voucher->status !== 'VOID'), 422, 'ต้องยกเลิกใบสำคัญจ่ายทั้งหมดก่อนจึงจะยกเลิกใบขอจ่ายทั้งชุดได้');

            $openRequests = $paymentRequests->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED']);
            abort_if($openRequests->isEmpty(), 422, 'ใบขอจ่ายทั้งชุดถูกยกเลิกครบแล้ว');
            foreach ($openRequests as $paymentRequest) {
                $before = $paymentRequest->only(['status']);
                $paymentRequest->update(['status' => 'CANCELLED', 'cancelled_by' => $request->user()->id, 'cancelled_at' => now(), 'cancellation_reason' => $data['reason']]);
                $audit->record('finance.commission_payment_request.cancelled', $paymentRequest, $before, ['status' => $paymentRequest->status, 'reason' => $data['reason']], $request->user(), $request);
            }

            return $openRequests->count();
        });

        return response()->json(['status' => true, 'msg' => 'ยกเลิกใบขอจ่าย '.$cancelledCount.' รายการแล้ว', 'redirect' => route('finance.commission-payouts.show', $commissionPaymentBatch)]);
    }

    public function approveAllRequests(Request $request, CommissionPaymentBatch $commissionPaymentBatch, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $commissionPaymentBatch);
        $approvedCount = DB::transaction(function () use ($request, $commissionPaymentBatch, $audit): int {
            $batch = CommissionPaymentBatch::query()->lockForUpdate()->findOrFail($commissionPaymentBatch->id);
            abort_unless($batch->status === 'VERIFIED', 422, 'ชุดจ่ายนี้ยังไม่พร้อมอนุมัติ');
            $paymentRequests = CommissionPaymentRequest::query()->where('payment_batch_id', $batch->id)->lockForUpdate()->get();
            $recipientCount = $batch->lines()->with('commissionRecord')->get()->map(fn ($line) => $line->commissionRecord?->recipient_user_id)->filter()->unique()->count();
            abort_if($paymentRequests->count() !== $recipientCount || ! $paymentRequests->every(fn (CommissionPaymentRequest $paymentRequest) => $paymentRequest->status === 'SUBMITTED'), 422, 'ใบขอจ่ายต้องครบทุกพนักงานและอยู่สถานะรออนุมัติทั้งหมด');

            foreach ($paymentRequests as $paymentRequest) {
                $paymentRequest->update(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
                $audit->record('finance.commission_payment_request.approved', $paymentRequest, ['status' => 'SUBMITTED'], $paymentRequest->only(['status', 'approved_by', 'approved_at']), $request->user(), $request);
            }

            return $paymentRequests->count();
        });

        return response()->json(['status' => true, 'msg' => 'อนุมัติใบขอจ่าย '.$approvedCount.' รายการแล้ว', 'redirect' => route('finance.commission-payouts.show', $commissionPaymentBatch)]);
    }

    public function createAllVouchers(Request $request, CommissionPaymentBatch $commissionPaymentBatch, AuditLogger $audit): JsonResponse
    {
        $this->scope($request, $commissionPaymentBatch);
        $createdCount = DB::transaction(function () use ($request, $commissionPaymentBatch, $audit): int {
            $batch = CommissionPaymentBatch::query()->lockForUpdate()->findOrFail($commissionPaymentBatch->id);
            abort_unless($batch->status === 'VERIFIED', 422, 'ชุดจ่ายนี้ยังไม่พร้อมสร้างใบสำคัญจ่าย');
            $paymentRequests = CommissionPaymentRequest::query()->where('payment_batch_id', $batch->id)->lockForUpdate()->get();
            $readyRequests = $paymentRequests->filter(fn (CommissionPaymentRequest $paymentRequest) => $paymentRequest->status === 'APPROVED' && ! $paymentRequest->payment_voucher_id);
            abort_if($readyRequests->isEmpty(), 422, 'ไม่มีใบขอจ่ายที่พร้อมสร้างใบสำคัญจ่าย');

            foreach ($readyRequests as $paymentRequest) {
                $voucher = PaymentVoucher::create([
                    'warehouse_id' => $request->attributes->get('selectedWarehouse')->id,
                    'voucher_type' => 'PAYMENT',
                    'document_number' => 'TEMP-'.str()->upper(str()->random(12)),
                    'document_date' => today(),
                    'party_id' => $paymentRequest->supplier_party_id,
                    'amount' => $paymentRequest->amount,
                    'description' => "ค่าคอมมิชชั่น {$paymentRequest->document_number}",
                    'status' => 'DRAFT',
                    'created_by' => $request->user()->id,
                ]);
                $voucher->update(['document_number' => 'PV-'.str_pad((string) $voucher->id, 8, '0', STR_PAD_LEFT)]);
                $paymentRequest->update(['payment_voucher_id' => $voucher->id]);
                $audit->record('finance.payment_voucher.created', $voucher, [], $voucher->only(['voucher_type', 'document_number', 'document_date', 'party_id', 'amount', 'description']), $request->user(), $request);
                $audit->record('finance.commission_payment_request.voucher_created', $paymentRequest, [], ['payment_voucher_id' => $voucher->id, 'payment_voucher_number' => $voucher->document_number], $request->user(), $request);
            }

            return $readyRequests->count();
        });

        return response()->json(['status' => true, 'msg' => 'สร้างใบสำคัญจ่าย '.$createdCount.' รายการแล้ว', 'redirect' => route('finance.commission-payouts.show', $commissionPaymentBatch)]);
    }

    public function showRequest(Request $request, CommissionPaymentRequest $commissionRequest): View
    {
        $commissionRequest->load('paymentBatch', 'recipient', 'supplier', 'voucher.settlement');
        abort_unless((int) $commissionRequest->paymentBatch->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
        $history = AuditLog::query()->with('user:id,name')->where('subject_type', $commissionRequest->getMorphClass())->where('subject_id', $commissionRequest->id)->latest('created_at')->latest('id')->get();

        return view('Finance::commission-requests.show', compact('commissionRequest', 'history'));
    }

    public function submitRequest(Request $request, CommissionPaymentRequest $commissionRequest, AuditLogger $audit): JsonResponse
    {
        abort_unless((int) $commissionRequest->paymentBatch->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
        abort_unless($commissionRequest->status === 'DRAFT', 422);
        $commissionRequest->update(['status' => 'SUBMITTED', 'submitted_by' => $request->user()->id, 'submitted_at' => now()]);
        $audit->record('finance.commission_payment_request.submitted', $commissionRequest, ['status' => 'DRAFT'], $commissionRequest->only(['status', 'submitted_by', 'submitted_at']), $request->user(), $request);

        return response()->json(['status' => true, 'redirect' => route('finance.commission-requests.show', $commissionRequest)]);
    }

    public function approveRequest(Request $request, CommissionPaymentRequest $commissionRequest, AuditLogger $audit): JsonResponse
    {
        abort_unless((int) $commissionRequest->paymentBatch->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
        abort_unless($commissionRequest->status === 'SUBMITTED', 422);
        $commissionRequest->update(['status' => 'APPROVED', 'approved_by' => $request->user()->id, 'approved_at' => now()]);
        $audit->record('finance.commission_payment_request.approved', $commissionRequest, ['status' => 'SUBMITTED'], $commissionRequest->only(['status', 'approved_by', 'approved_at']), $request->user(), $request);

        return response()->json(['status' => true, 'redirect' => route('finance.commission-requests.show', $commissionRequest)]);
    }

    public function cancelRequest(Request $request, CommissionPaymentRequest $commissionRequest, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:500']]);
        $cancelled = DB::transaction(function () use ($request, $commissionRequest, $data, $audit): CommissionPaymentRequest {
            $locked = CommissionPaymentRequest::query()->with('voucher')->lockForUpdate()->findOrFail($commissionRequest->id);
            $batch = CommissionPaymentBatch::query()->lockForUpdate()->findOrFail($locked->payment_batch_id);
            abort_unless((int) $batch->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
            if (! in_array($locked->status, ['DRAFT', 'SUBMITTED', 'APPROVED'], true)) {
                abort(422, 'สถานะใบขอจ่ายนี้ไม่สามารถยกเลิกได้');
            }
            if ($locked->voucher && $locked->voucher->status !== 'VOID') {
                abort(422, 'ต้องยกเลิกใบสำคัญจ่ายก่อนจึงจะยกเลิกใบขอจ่ายได้');
            }
            $before = $locked->only(['status']);
            $locked->update(['status' => 'CANCELLED', 'cancelled_by' => $request->user()->id, 'cancelled_at' => now(), 'cancellation_reason' => $data['reason']]);
            $audit->record('finance.commission_payment_request.cancelled', $locked, $before, ['status' => $locked->status, 'reason' => $data['reason']], $request->user(), $request);

            return $locked;
        });

        return response()->json(['status' => true, 'redirect' => route('finance.commission-requests.show', $cancelled)]);
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

        return ['label' => "ยังไม่สร้าง 0/{$total}", 'class' => 'app-status-neutral'];
    }

    private function paymentSummary(CommissionPaymentBatch $batch): array
    {
        $total = $batch->lines->pluck('commissionRecord.recipient_user_id')->filter()->unique()->count();
        $requests = $batch->paymentRequests;
        $paid = $requests->filter(fn (CommissionPaymentRequest $paymentRequest) => $paymentRequest->voucher?->settlement?->status === 'POSTED')->count();
        $voided = $requests->filter(fn (CommissionPaymentRequest $paymentRequest) => $paymentRequest->voucher?->status === 'VOID')->count();
        $processing = $requests->filter(fn (CommissionPaymentRequest $paymentRequest) => $paymentRequest->voucher && $paymentRequest->voucher->status !== 'VOID' && $paymentRequest->voucher->settlement?->status !== 'POSTED')->count();
        $ready = $requests->filter(fn (CommissionPaymentRequest $paymentRequest) => $paymentRequest->status === 'APPROVED' && ! $paymentRequest->voucher)->count();

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

        return ['label' => "ยังไม่พร้อมจ่าย 0/{$total}", 'class' => 'app-status-neutral'];
    }
}
