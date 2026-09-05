<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\InternalTransfer;
use App\Modules\Finance\Requests\PettyCashActionRequest;
use App\Modules\Finance\Requests\SaveInternalTransferRequest;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Finance\Services\InternalTransferService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class InternalTransferController extends Controller
{
    public function index(Request $request): View { return view('Finance::internal-transfers.index', ['accounts' => $this->accounts($request)]); }

    public function data(Request $request): JsonResponse
    {
        $query = InternalTransfer::query()->with(['sourceBankAccount', 'destinationBankAccount'])->where('warehouse_id', $this->warehouse($request)->id);
        $this->applyFilters($query, $request);
        $query->orderByDesc('document_date')->orderByDesc('id');
        return DataTables::eloquent($query)->addColumn('source_label', fn (InternalTransfer $t) => $t->sourceBankAccount?->code.' · '.$t->sourceBankAccount?->name)->addColumn('destination_label', fn (InternalTransfer $t) => $t->destinationBankAccount?->code.' · '.$t->destinationBankAccount?->name)->addColumn('date_label', fn (InternalTransfer $t) => $t->document_date?->format('d/m/Y'))->addColumn('show_url', fn (InternalTransfer $t) => route('finance.internal-transfers.show', $t))->toJson();
    }

    public function create(Request $request): View
    {
        $accounts = BankAccount::query()->where('warehouse_id', $this->warehouse($request)->id)->where('is_active', true)->where('currency_code', 'THB')->with('account')->whereHas('account', fn (Builder $q) => $q->where('is_active', true)->where('is_postable', true))->orderBy('code')->get();
        return view('Finance::internal-transfers.form', ['accounts' => $accounts]);
    }

    public function edit(Request $request, InternalTransfer $transfer): View
    {
        $this->scope($request, $transfer);
        abort_unless($transfer->status === 'DRAFT', 422, 'แก้ไขได้เฉพาะเอกสาร Draft');
        return view('Finance::internal-transfers.form', ['accounts' => $this->accounts($request), 'transfer' => $transfer]);
    }

    public function store(SaveInternalTransferRequest $request, InternalTransferService $service, DocumentSequenceService $sequences): JsonResponse
    {
        $warehouse = $this->warehouse($request);
        $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'INTERNAL_TRANSFER')->where('is_active', true)->first() ?? throw ValidationException::withMessages(['document_sequence' => 'ยังไม่ได้ตั้งค่าเลขเอกสาร INTERNAL_TRANSFER']);
        $transfer = $service->create($request->validated(), $warehouse, $sequence, $request->user(), $request);
        return response()->json(['status' => true, 'msg' => 'สร้างร่างเอกสารโอนเงินแล้ว', 'redirect' => route('finance.internal-transfers.show', $transfer)], 201);
    }

    public function update(SaveInternalTransferRequest $request, InternalTransfer $transfer, InternalTransferService $service): JsonResponse
    {
        $this->scope($request, $transfer);
        $transfer = $service->update($transfer, $request->validated(), $this->warehouse($request), $request->user(), $request);
        return response()->json(['status' => true, 'msg' => 'บันทึกเอกสารโอนเงินแล้ว', 'redirect' => route('finance.internal-transfers.show', $transfer)]);
    }

    public function destroy(Request $request, InternalTransfer $transfer, InternalTransferService $service): JsonResponse
    {
        $this->scope($request, $transfer);
        $service->deleteDraft($transfer, $this->warehouse($request), $request->user(), $request);
        return response()->json(['status' => true, 'msg' => 'ลบเอกสาร Draft แล้ว', 'redirect' => route('finance.internal-transfers.index')]);
    }

    public function show(Request $request, InternalTransfer $transfer): View
    {
        $this->scope($request, $transfer);
        return view('Finance::internal-transfers.show', ['transfer' => $transfer->load(['sourceBankAccount', 'destinationBankAccount', 'journalEntry', 'reversalJournalEntry']), 'history' => AuditLog::query()->with('user')->where('subject_type', $transfer->getMorphClass())->where('subject_id', $transfer->id)->latest('created_at')->latest('id')->get(), 'dateFormat' => 'd/m/Y', 'labels' => ['DRAFT' => 'ร่าง', 'SUBMITTED' => 'รออนุมัติ', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'REVERSED' => 'กลับรายการแล้ว', 'VOID' => 'ยกเลิก'], 'classes' => ['DRAFT' => 'app-status-neutral', 'SUBMITTED' => 'app-status-info', 'APPROVED' => 'app-status-success', 'POSTED' => 'app-status-success', 'REVERSED' => 'app-status-warning', 'VOID' => 'app-status-danger']]);
    }

    public function submit(PettyCashActionRequest $request, InternalTransfer $transfer, InternalTransferService $service): JsonResponse { return $this->action($request, $transfer, $service, 'SUBMIT'); }
    public function approve(PettyCashActionRequest $request, InternalTransfer $transfer, InternalTransferService $service): JsonResponse { return $this->action($request, $transfer, $service, 'APPROVE'); }
    public function void(PettyCashActionRequest $request, InternalTransfer $transfer, InternalTransferService $service): JsonResponse { return $this->action($request, $transfer, $service, 'VOID'); }
    public function post(PettyCashActionRequest $request, InternalTransfer $transfer, InternalTransferService $service): JsonResponse { $this->scope($request, $transfer); $result = $service->post($transfer, $this->warehouse($request), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'ลงบัญชีเอกสารโอนเงินแล้ว', 'data' => $result]); }
    public function reverse(PettyCashActionRequest $request, InternalTransfer $transfer, InternalTransferService $service): JsonResponse { $this->scope($request, $transfer); $v = $request->validated(); $result = $service->reverse($transfer, $this->warehouse($request), (string) $v['reversal_date'], (string) ($v['reason'] ?? ''), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'กลับรายการโอนเงินแล้ว', 'data' => $result]); }

    private function action(PettyCashActionRequest $request, InternalTransfer $transfer, InternalTransferService $service, string $action): JsonResponse { $this->scope($request, $transfer); $result = $service->transition($transfer, $this->warehouse($request), $action, $request->user(), $request); return response()->json(['status' => true, 'msg' => 'อัปเดตสถานะเอกสารโอนเงินแล้ว', 'data' => $result]); }
    private function warehouse(Request $request) { return $request->attributes->get('selectedWarehouse'); }
    private function accounts(Request $request) { return BankAccount::query()->where('warehouse_id', $this->warehouse($request)->id)->where('is_active', true)->where('currency_code', 'THB')->with('account')->whereHas('account', fn (Builder $q) => $q->where('is_active', true)->where('is_postable', true))->orderBy('code')->get(); }
    private function scope(Request $request, InternalTransfer $transfer): void { abort_unless((int) $transfer->warehouse_id === (int) $this->warehouse($request)->id, 404); }
    private function applyFilters(Builder $query, Request $request): void
    {
        $query->when($request->filled('status') && in_array($request->input('status'), ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'REVERSED', 'VOID'], true), fn (Builder $query) => $query->where('status', $request->input('status')))
            ->when($request->filled('source_bank_account_id'), fn (Builder $query) => $query->where('source_bank_account_id', (int) $request->input('source_bank_account_id')))
            ->when($request->filled('destination_bank_account_id'), fn (Builder $query) => $query->where('destination_bank_account_id', (int) $request->input('destination_bank_account_id')))
            ->when($request->filled('date_from'), fn (Builder $query) => $query->whereDate('document_date', '>=', $request->input('date_from')))
            ->when($request->filled('date_to'), fn (Builder $query) => $query->whereDate('document_date', '<=', $request->input('date_to')))
            ->when($request->filled('amount_min') && is_numeric($request->input('amount_min')), fn (Builder $query) => $query->where('amount', '>=', (float) $request->input('amount_min')))
            ->when($request->filled('amount_max') && is_numeric($request->input('amount_max')), fn (Builder $query) => $query->where('amount', '<=', (float) $request->input('amount_max')));
    }
}
