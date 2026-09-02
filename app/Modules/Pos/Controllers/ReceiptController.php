<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Finance\Controllers\SettlementController;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Requests\ChangeSettlementStatusRequest;
use App\Modules\Finance\Requests\PostSettlementRequest;
use App\Modules\Finance\Requests\ReverseSettlementRequest;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Finance\Services\SettlementPostingService;
use App\Modules\Finance\Services\SettlementReversalService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Requests\SavePosReceiptRequest;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class ReceiptController extends Controller
{
    public function index(): View
    {
        return view('Pos::receipts.index');
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'party_id' => ['nullable', 'integer'],
            'status' => ['nullable', Rule::in(['DRAFT', 'APPROVED', 'POSTED', 'VOID', 'REVERSED'])],
        ]);
        $branchId = $request->attributes->get('selectedBranch')->id;
        $format = (string) ($settings->value('date_format') ?: 'd/m/Y');
        $receipts = Settlement::query()->with(['party', 'bankAccount'])
            ->where('document_type', 'RECEIPT')
            ->whereHas('bankAccount.warehouse', fn ($query) => $query->where('branch_id', $branchId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('settlement_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('settlement_date', '<=', $date))
            ->when($filters['party_id'] ?? null, fn (Builder $query, int $partyId) => $query->where('party_id', $partyId))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status));

        return DataTables::eloquent($receipts)
            ->order(fn (Builder $query) => $query->orderByDesc('settlement_date')->orderByDesc('id'))
            ->addColumn('settlement_date_label', fn (Settlement $receipt) => $receipt->settlement_date?->format($format) ?: '—')
            ->addColumn('party_label', fn (Settlement $receipt) => ($receipt->party?->code ?: '—').' · '.($receipt->party?->name ?: '—'))
            ->addColumn('bank_label', fn (Settlement $receipt) => ($receipt->bankAccount?->code ?: '—').' · '.($receipt->bankAccount?->name ?: '—'))
            ->addColumn('status_label', fn (Settlement $receipt) => ['DRAFT' => 'ร่าง', 'APPROVED' => 'อนุมัติแล้ว', 'POSTED' => 'ลงบัญชีแล้ว', 'VOID' => 'ยกเลิก', 'REVERSED' => 'ยกเลิกแล้ว'][$receipt->status] ?? $receipt->status)
            ->addColumn('show_url', fn (Settlement $receipt) => route('pos.receipts.show', $receipt))
            ->toJson();
    }

    public function create(Request $request, OpenItemService $openItems): View
    {
        if ($request->filled('open_item_id')) {
            $this->selectOpenItemWarehouse($request);
        }
        $data = $this->finance()->create($request, $openItems)->getData();

        if (! $request->filled('open_item_id')) {
            $data['bankAccounts'] = $this->branchBankAccounts($request);
        }

        return view('Finance::settlements.form', [...$data, 'layout' => 'Pos::layout', 'posReceiptMode' => true,
            'storeUrl' => route('pos.receipts.store'), 'partyOptionsUrl' => route('pos.receipts.party-options'),
            'openItemOptionsUrl' => route('pos.receipts.open-item-options'), 'backUrl' => route('pos.receipts.index')]);
    }

    public function partyOptions(Request $request): JsonResponse
    {
        $this->selectBankWarehouse($request);
        $request->merge(['document_type' => 'RECEIPT']);

        return $this->finance()->partyOptions($request);
    }

    public function openItemOptions(Request $request, GlobalSettings $settings): JsonResponse
    {
        $this->selectBankWarehouse($request);
        $request->merge(['document_type' => 'RECEIPT']);

        return $this->finance()->openItemOptions($request, $settings);
    }

    public function store(SavePosReceiptRequest $request, AuditLogger $audit, DocumentSequenceService $sequences, OpenItemService $openItems): JsonResponse
    {
        $payload = $this->finance()->store($request, $audit, $sequences, $openItems)->getData(true);
        $settlement = Settlement::query()->findOrFail($payload['settlement_id']);

        return response()->json([...$payload, 'redirect' => route('pos.receipts.show', $settlement)]);
    }

    public function show(Request $request, Settlement $receipt, GlobalSettings $settings, SettlementPostingService $posting): View
    {
        $receipt = $this->receipt($request, $receipt)->load(['party', 'bankAccount', 'journalEntry', 'tenders.bankAccount', 'allocationIntents.openItem']);
        $history = AuditLog::query()->with('user')->where('subject_type', $receipt->getMorphClass())->where('subject_id', $receipt->id)->latest('created_at')->latest('id')->get();

        return view('Pos::receipts.show', ['receipt' => $receipt, 'history' => $history, 'dateFormat' => (string) $settings->value('date_format'), 'postReadiness' => $posting->postReadiness($receipt)]);
    }

    public function approve(ChangeSettlementStatusRequest $request, Settlement $receipt, AuditLogger $audit): JsonResponse
    {
        return $this->finance()->approve($request, $this->receipt($request, $receipt), $audit);
    }

    public function post(PostSettlementRequest $request, Settlement $receipt, SettlementPostingService $posting, AuditLogger $audit): JsonResponse
    {
        return $this->finance()->post($request, $this->receipt($request, $receipt), $posting, $audit);
    }

    public function reverse(ReverseSettlementRequest $request, Settlement $receipt, SettlementReversalService $reversal, AuditLogger $audit): JsonResponse
    {
        return $this->finance()->reverse($request, $this->receipt($request, $receipt), $reversal, $audit);
    }

    private function receipt(Request $request, Settlement $receipt): Settlement
    {
        $branchId = $request->attributes->get('selectedBranch')->id;
        $warehouse = $receipt->bankAccount ? $request->user()->warehouses()
            ->whereKey($receipt->bankAccount->warehouse_id)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->first() : null;
        abort_unless($receipt->document_type === 'RECEIPT' && $warehouse, 404);
        $request->attributes->set('selectedWarehouse', $warehouse);

        return $receipt;
    }

    private function branchBankAccounts(Request $request)
    {
        return BankAccount::query()
            ->where('is_active', true)
            ->whereIn('warehouse_id', $request->user()->warehouses()
                ->where('branch_id', $request->attributes->get('selectedBranch')->id)
                ->where('is_active', true)
                ->select('warehouses.id'))
            ->with('warehouse:id,name')
            ->orderBy('code')
            ->get(['id', 'warehouse_id', 'code', 'name']);
    }

    private function selectBankWarehouse(Request $request): void
    {
        $bankAccountId = $request->integer('bank_account_id');
        if (! $bankAccountId) {
            throw ValidationException::withMessages(['bank_account_id' => 'กรุณาเลือกบัญชีเงินสด/ธนาคารก่อน']);
        }

        $branchId = $request->attributes->get('selectedBranch')->id;
        $bankAccount = BankAccount::query()
            ->whereKey($bankAccountId)
            ->where('is_active', true)
            ->whereHas('warehouse', fn ($query) => $query->where('branch_id', $branchId)->where('is_active', true))
            ->first();
        $warehouse = $bankAccount ? $request->user()->warehouses()
            ->whereKey($bankAccount->warehouse_id)
            ->where('branch_id', $branchId)
            ->where('is_active', true)
            ->first() : null;

        if (! $warehouse) {
            throw ValidationException::withMessages(['bank_account_id' => 'บัญชีเงินสด/ธนาคารไม่อยู่ในสาขาหรือไม่มีสิทธิ์ใช้งาน']);
        }

        $request->attributes->set('selectedWarehouse', $warehouse);
    }

    private function selectOpenItemWarehouse(Request $request): void
    {
        $openItem = OpenItem::query()->findOrFail($request->integer('open_item_id'));
        $warehouse = $request->user()->warehouses()
            ->whereKey($openItem->warehouse_id)
            ->where('branch_id', $request->attributes->get('selectedBranch')->id)
            ->where('is_active', true)
            ->first();
        abort_unless($warehouse, 404);
        $request->attributes->set('selectedWarehouse', $warehouse);
    }

    private function finance(): SettlementController
    {
        return app(SettlementController::class);
    }
}
