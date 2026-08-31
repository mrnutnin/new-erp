<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Party;
use App\Models\PartyRole;
use App\Modules\Accounting\Support\JournalBalance;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\CommissionPaymentRequest;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\PaymentVoucher;
use App\Modules\Finance\Models\PaymentVoucherLine;
use App\Modules\Finance\Requests\ChangePaymentVoucherStatusRequest;
use App\Modules\Finance\Requests\SavePaymentVoucherRequest;
use App\Modules\Finance\Services\OpenItemService;
use App\Modules\Finance\Services\PaymentVoucherSettlementService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\CommissionPaymentBatch;
use App\Modules\Settings\Services\GlobalSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class PaymentVoucherController extends Controller
{
    public function index(): View
    {
        return view('Finance::payment-vouchers.index');
    }

    public function data(Request $request, GlobalSettings $settings): JsonResponse
    {
        $dateFormat = (string) $settings->value('date_format');
        $dataTable = DataTables::eloquent($this->query($request))
            ->filter(fn (Builder $query) => $this->search($query, $request))
            ->order(fn (Builder $query) => $this->order($query, $request))
            ->addColumn('date_label', fn (PaymentVoucher $voucher) => $voucher->document_date->format($dateFormat))
            ->addColumn('type_label', fn (PaymentVoucher $voucher) => $voucher->voucher_type === 'PRE_PAYMENT' ? 'ใบขอจ่ายล่วงหน้า' : 'ใบสำคัญจ่าย')
            ->addColumn('party_label', fn (PaymentVoucher $voucher) => $voucher->party_code ? $voucher->party_code.' · '.$voucher->party_name : '—')
            ->addColumn('bank_label', fn (PaymentVoucher $voucher) => $voucher->bank_code ? $voucher->bank_code.' · '.$voucher->bank_name : '—');

        if ($request->user()->hasPermission('finance.payment-vouchers.submit')) {
            $dataTable->addColumn('submit_url', fn (PaymentVoucher $voucher) => $voucher->status === 'DRAFT' ? route('finance.payment-vouchers.submit', $voucher) : null);
        }
        if ($request->user()->hasPermission('finance.payment-vouchers.approve')) {
            $dataTable->addColumn('approve_url', fn (PaymentVoucher $voucher) => $voucher->status === 'SUBMITTED' ? route('finance.payment-vouchers.approve', $voucher) : null);
        }
        if ($request->user()->hasPermission('finance.payment-vouchers.void')) {
            $dataTable->addColumn('void_url', fn (PaymentVoucher $voucher) => in_array($voucher->status, ['DRAFT', 'SUBMITTED', 'APPROVED'], true) ? route('finance.payment-vouchers.void', $voucher) : null);
        }
        if ($request->user()->hasPermission('finance.payment-vouchers.settle')) {
            $dataTable->addColumn('settle_url', fn (PaymentVoucher $voucher) => $voucher->status === 'APPROVED' && ! $voucher->settlement_id ? route('finance.payment-vouchers.settle', $voucher) : null);
        }
        $dataTable->addColumn('show_url', fn (PaymentVoucher $voucher) => route('finance.payment-vouchers.show', $voucher));

        return $dataTable->toJson();
    }

    public function show(Request $request, PaymentVoucher $voucher, GlobalSettings $settings): View
    {
        $voucher = PaymentVoucher::query()->withTrashed()->with(['party', 'bankAccount', 'lines.openItem', 'settlement'])->findOrFail($voucher->id);
        $this->scopeVoucher($request, $voucher);
        $history = AuditLog::query()->with('user')->where('subject_type', $voucher->getMorphClass())->where('subject_id', $voucher->id)->latest('created_at')->latest('id')->get();

        return view('Finance::payment-vouchers.show', [
            'voucher' => $voucher,
            'history' => $history,
            'dateFormat' => (string) $settings->value('date_format'),
        ]);
    }

    public function create(Request $request): View
    {
        $commissionRequest = null;
        if ($request->filled('commission_request_id')) {
            $commissionRequest = CommissionPaymentRequest::query()->with('paymentBatch', 'supplier')->findOrFail($request->integer('commission_request_id'));
            abort_unless((int) $commissionRequest->paymentBatch->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
            abort_unless($commissionRequest->status === 'APPROVED' && ! $commissionRequest->payment_voucher_id, 422);
        }

        return view('Finance::payment-vouchers.form', [
            'voucher' => new PaymentVoucher(['voucher_type' => 'PAYMENT', 'document_date' => today(), 'party_id' => $commissionRequest?->supplier_party_id, 'amount' => $commissionRequest?->amount ?? 0, 'description' => $commissionRequest ? "ค่าคอมมิชชั่น {$commissionRequest->document_number}" : null]),
            'commissionRequest' => $commissionRequest,
            'bankAccounts' => BankAccount::query()->where('is_active', true)->whereIn('warehouse_id', $this->authorizedWarehouseIds($request))->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function partyOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q'));
        $page = max(1, (int) $request->input('page', 1));
        $rows = Party::query()->join('party_roles', function ($join) {
            $join->on('party_roles.party_id', '=', 'parties.id')->where('party_roles.role', 'SUPPLIER')->where('party_roles.is_active', true);
        })->where('parties.is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('parties.code')->forPage($page, 31)->get(['parties.id', 'parties.code', 'parties.name']);

        return response()->json(['results' => $rows->take(30)->map(fn (Party $party) => ['id' => $party->id, 'text' => $party->code.' · '.$party->name])->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function openItemOptions(Request $request, GlobalSettings $settings): JsonResponse
    {
        $warehouseIds = $this->authorizedWarehouseIds($request);
        $search = trim((string) $request->input('q'));
        $page = max(1, (int) $request->input('page', 1));
        $allocationRows = DB::table('finance_allocations')
            ->selectRaw('debit_open_item_id as open_item_id, amount')
            ->whereNull('reversed_at')
            ->unionAll(DB::table('finance_allocations')
                ->selectRaw('credit_open_item_id as open_item_id, amount')
                ->whereNull('reversed_at'));
        $allocated = DB::query()->fromSub($allocationRows, 'allocation_rows')
            ->selectRaw('open_item_id, SUM(amount) as allocated_amount')
            ->groupBy('open_item_id');
        $query = DB::table('finance_open_items as oi')
            ->join('parties', 'parties.id', '=', 'oi.party_id')
            ->leftJoinSub($allocated, 'allocated', 'allocated.open_item_id', '=', 'oi.id')
            ->whereIn('oi.warehouse_id', $warehouseIds)->where('oi.ledger_type', 'AP')->where('oi.party_type', 'SUPPLIER')
            ->where('oi.balance_side', 'CREDIT')
            ->where('oi.posting_date', '<=', now()->toDateString())
            ->when($request->filled('party_id'), fn ($q) => $q->where('oi.party_id', $request->integer('party_id')))
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => $q->where('oi.document_number', 'like', "%{$search}%")->orWhere('parties.code', 'like', "%{$search}%")->orWhere('parties.name', 'like', "%{$search}%")))
            ->whereRaw('oi.original_amount - COALESCE(allocated.allocated_amount, 0) > 0')
            ->orderBy('oi.due_date')->orderBy('oi.id')->forPage($page, 31)
            ->get(['oi.id', 'oi.document_number', 'oi.document_date', 'oi.original_amount', 'parties.code as party_code', 'parties.name as party_name', DB::raw('oi.original_amount - COALESCE(allocated.allocated_amount, 0) as remaining_amount')]);

        $dateFormat = (string) $settings->value('date_format');

        return response()->json(['results' => $query->take(30)->map(fn ($item) => [
            'id' => $item->id,
            'text' => $item->document_number.' · '.$item->party_code.' · คงเหลือ '.number_format((float) $item->remaining_amount, 2),
            'document_number' => $item->document_number,
            'document_date' => $item->document_date ? date($dateFormat, strtotime($item->document_date)) : '—',
            'remaining_amount' => $item->remaining_amount,
        ])->values(), 'pagination' => ['more' => $query->count() > 30]]);
    }

    public function store(SavePaymentVoucherRequest $request, AuditLogger $audit, OpenItemService $openItems): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $voucher = DB::transaction(function () use ($request, $warehouse, $audit, $openItems) {
            $values = $request->validated();
            $lines = $values['lines'] ?? [];
            $commissionRequestId = $values['commission_request_id'] ?? null;
            unset($values['lines'], $values['commission_request_id']);
            $commissionRequest = null;
            if ($commissionRequestId) {
                $commissionRequest = CommissionPaymentRequest::query()->with('paymentBatch')->lockForUpdate()->findOrFail($commissionRequestId);
                $paymentBatch = CommissionPaymentBatch::query()->lockForUpdate()->findOrFail($commissionRequest->payment_batch_id);
                abort_unless((int) $paymentBatch->branch_id === (int) $request->attributes->get('selectedBranch')->id, 404);
                if ($paymentBatch->status !== 'VERIFIED' || $commissionRequest->status !== 'APPROVED' || $commissionRequest->payment_voucher_id) {
                    abort(422, 'ใบขอจ่ายนี้ไม่พร้อมสร้างใบสำคัญจ่าย');
                }
                $values = [...$values, 'voucher_type' => 'PAYMENT', 'party_id' => $commissionRequest->supplier_party_id, 'amount' => $commissionRequest->amount, 'description' => "ค่าคอมมิชชั่น {$commissionRequest->document_number}"];
                $lines = [];
            }
            if (! empty($values['bank_account_id'])) {
                $warehouse = BankAccount::query()->whereKey($values['bank_account_id'])->whereIn('warehouse_id', $this->authorizedWarehouseIds($request))->firstOrFail()->warehouse;
            }
            if (! empty($values['party_id']) && ! PartyRole::query()->where('party_id', $values['party_id'])->where('role', 'SUPPLIER')->where('is_active', true)->exists()) {
                abort(422, 'คู่ค้าต้องมีบทบาท Supplier ที่เปิดใช้งาน');
            }
            $voucher = PaymentVoucher::create([...$values, 'warehouse_id' => $warehouse->id, 'document_number' => 'TEMP-'.str()->upper(str()->random(12)), 'status' => 'DRAFT', 'created_by' => $request->user()->id]);
            $voucher->update(['document_number' => ($voucher->voucher_type === 'PRE_PAYMENT' ? 'PPV-' : 'PV-').str_pad((string) $voucher->id, 8, '0', STR_PAD_LEFT)]);
            $lineTotal = JournalBalance::totals(array_map(fn (array $line) => ['debit' => $line['amount'], 'credit' => '0.00'], $lines))['debit'];
            $voucherTotal = JournalBalance::totals([['debit' => $voucher->amount, 'credit' => '0.00']])['debit'];
            if ($lineTotal > $voucherTotal) {
                abort(422, 'ยอดรวมรายการจัดสรรเกินยอดใบสำคัญ');
            }
            foreach (array_values($lines) as $index => $line) {
                $openItem = null;
                if (! empty($line['open_item_id'])) {
                    $openItem = OpenItem::query()->lockForUpdate()->findOrFail($line['open_item_id']);
                    if ($openItem->warehouse_id !== $warehouse->id || $openItem->ledger_type !== 'AP' || $openItem->party_type !== 'SUPPLIER' || ($voucher->party_id && (int) $openItem->party_id !== (int) $voucher->party_id)) {
                        abort(422, 'Open Item ต้องเป็นเจ้าหนี้ของสาขาและ Supplier เดียวกับใบสำคัญ');
                    }
                    $openItems->assertAmountAvailable($openItem, $voucher->document_date->format('Y-m-d'), $line['amount']);
                }
                PaymentVoucherLine::create([
                    'payment_voucher_id' => $voucher->id, 'line_number' => $index + 1, 'open_item_id' => $openItem?->id,
                    'open_item_document_number' => $openItem?->document_number, 'open_item_original_amount' => $openItem?->original_amount,
                    'amount' => $line['amount'], 'description' => $line['description'] ?? null,
                    'allocation_key' => hash('sha256', $voucher->id.'|'.($openItem?->id ?? 'line-'.$index)),
                ]);
            }
            $audit->record('finance.payment_voucher.created', $voucher, [], $voucher->only(['voucher_type', 'document_number', 'document_date', 'party_id', 'bank_account_id', 'amount', 'description']), $request->user(), $request);
            if ($commissionRequest) {
                $commissionRequest->update(['payment_voucher_id' => $voucher->id]);
                $audit->record('finance.commission_payment_request.voucher_created', $commissionRequest, [], ['payment_voucher_id' => $voucher->id, 'payment_voucher_number' => $voucher->document_number], $request->user(), $request);
            }

            return $voucher;
        });

        return response()->json(['status' => true, 'msg' => 'สร้างใบสำคัญการจ่ายแล้ว', 'redirect' => route('finance.payment-vouchers.index')]);
    }

    public function submit(ChangePaymentVoucherStatusRequest $request, PaymentVoucher $voucher, AuditLogger $audit): JsonResponse
    {
        return $this->transition($request, $voucher, 'SUBMITTED', 'finance.payment_voucher.submitted', 'ส่งขออนุมัติแล้ว', $audit);
    }

    public function approve(ChangePaymentVoucherStatusRequest $request, PaymentVoucher $voucher, AuditLogger $audit): JsonResponse
    {
        return $this->transition($request, $voucher, 'APPROVED', 'finance.payment_voucher.approved', 'อนุมัติใบสำคัญแล้ว', $audit);
    }

    public function void(ChangePaymentVoucherStatusRequest $request, PaymentVoucher $voucher, AuditLogger $audit): JsonResponse
    {
        return $this->transition($request, $voucher, 'VOID', 'finance.payment_voucher.voided', 'ยกเลิกใบสำคัญแล้ว', $audit);
    }

    public function settle(Request $request, PaymentVoucher $voucher, PaymentVoucherSettlementService $service, AuditLogger $audit): JsonResponse
    {
        $this->scopeVoucher($request, $voucher);
        $settlement = $service->create($voucher, $voucher->warehouse_id, $request, $audit);

        return response()->json(['status' => true, 'msg' => "สร้างร่าง Settlement {$settlement->document_number} แล้ว", 'redirect' => route('finance.settlements.index')]);
    }

    private function transition(Request $request, PaymentVoucher $voucher, string $status, string $event, string $message, AuditLogger $audit): JsonResponse
    {
        $this->scopeVoucher($request, $voucher);

        DB::transaction(function () use ($request, $voucher, $status, $event, $audit) {
            $locked = PaymentVoucher::query()->lockForUpdate()->findOrFail($voucher->id);
            $allowed = ['SUBMITTED' => ['DRAFT'], 'APPROVED' => ['SUBMITTED'], 'VOID' => ['DRAFT', 'SUBMITTED', 'APPROVED']];
            if (! in_array($locked->status, $allowed[$status], true)) {
                abort(422, 'สถานะเอกสารไม่รองรับการเปลี่ยนแปลงนี้');
            }
            $before = $locked->only(['status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at']);
            $values = ['status' => $status];
            if ($status === 'SUBMITTED') {
                $values += ['submitted_by' => $request->user()->id, 'submitted_at' => now()];
            }
            if ($status === 'APPROVED') {
                $values += ['approved_by' => $request->user()->id, 'approved_at' => now()];
            }
            $locked->update($values);
            $audit->record($event, $locked, $before, $locked->only(array_keys($before)), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => $message]);
    }

    private function query(Request $request): Builder
    {
        return PaymentVoucher::query()->select('finance_payment_vouchers.*', 'parties.code as party_code', 'parties.name as party_name', 'bank_accounts.code as bank_code', 'bank_accounts.name as bank_name')
            ->leftJoin('parties', 'parties.id', '=', 'finance_payment_vouchers.party_id')
            ->leftJoin('party_roles', function ($join) {
                $join->on('party_roles.party_id', '=', 'parties.id')->where('party_roles.role', 'SUPPLIER')->where('party_roles.is_active', true);
            })
            ->leftJoin('finance_bank_accounts as bank_accounts', 'bank_accounts.id', '=', 'finance_payment_vouchers.bank_account_id')
            ->whereIn('finance_payment_vouchers.warehouse_id', $this->authorizedWarehouseIds($request));
    }

    /** @return list<int> */
    private function authorizedWarehouseIds(Request $request): array
    {
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)
            ->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function scopeVoucher(Request $request, PaymentVoucher $voucher): void
    {
        abort_unless(in_array((int) $voucher->warehouse_id, $this->authorizedWarehouseIds($request), true), 404);
    }

    private function search(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value'));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->where('finance_payment_vouchers.document_number', 'like', "%{$search}%")->orWhere('finance_payment_vouchers.voucher_type', 'like', "%{$search}%")->orWhere('finance_payment_vouchers.status', 'like', "%{$search}%")->orWhere('parties.code', 'like', "%{$search}%")->orWhere('parties.name', 'like', "%{$search}%"));
        }
    }

    private function order(Builder $query, Request $request): void
    {
        $columns = ['finance_payment_vouchers.document_number', 'finance_payment_vouchers.voucher_type', 'finance_payment_vouchers.document_date', 'parties.code', 'finance_payment_vouchers.amount', 'finance_payment_vouchers.status'];
        $query->reorder($columns[(int) $request->input('order.0.column', 2)] ?? $columns[2], $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc')->orderByDesc('finance_payment_vouchers.id');
    }
}
