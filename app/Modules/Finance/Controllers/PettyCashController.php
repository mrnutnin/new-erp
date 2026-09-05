<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\OtherCategory;
use App\Modules\Finance\Models\PettyCashFund;
use App\Modules\Finance\Models\PettyCashVoucher;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Finance\Requests\PettyCashActionRequest;
use App\Modules\Finance\Requests\SavePettyCashVoucherRequest;
use App\Modules\Finance\Services\PettyCashVoucherService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class PettyCashController extends Controller
{
    public function index(Request $request): View
    {
        return view('Finance::petty-cash.index', $this->options($request));
    }

    public function create(Request $request): View
    {
        return view('Finance::petty-cash.form', [...$this->options($request), 'voucher' => new PettyCashVoucher(['document_date' => today()])]);
    }

    public function edit(Request $request, PettyCashVoucher $voucher): View
    {
        $this->scopeVoucher($request, $voucher);
        abort_unless($voucher->status === 'DRAFT', 422, 'แก้ไขได้เฉพาะเอกสาร Draft');

        return view('Finance::petty-cash.form', [...$this->options($request), 'voucher' => $voucher->load('lines')]);
    }

    public function data(Request $request): JsonResponse
    {
        $query = PettyCashVoucher::query()->with('fund.cashBankAccount')
            ->where('warehouse_id', $request->attributes->get('selectedWarehouse')->id)
            ->when($request->filled('status'), fn (Builder $query) => $query->where('status', $request->string('status')))
            ->when($request->filled('petty_cash_fund_id'), fn (Builder $query) => $query->where('petty_cash_fund_id', $request->integer('petty_cash_fund_id')))
            ->orderByDesc('document_date')
            ->orderByDesc('id');
        $table = DataTables::eloquent($query)
            ->filter(fn (Builder $query) => $this->search($query, $request, ['document_number', 'payee_name', 'status']))
            ->addColumn('document_date_label', fn (PettyCashVoucher $voucher) => $voucher->document_date?->format('d/m/Y'))
            ->addColumn('fund_label', fn (PettyCashVoucher $voucher) => $voucher->fund ? $voucher->fund->name.' · '.$voucher->fund->cashBankAccount?->code : '—')
            ->addColumn('show_url', fn (PettyCashVoucher $voucher) => route('finance.petty-cash.show', $voucher));
        if ($request->user()->hasPermission('finance.petty-cash.update')) {
            $table->addColumn('edit_url', fn (PettyCashVoucher $voucher) => $voucher->status === 'DRAFT' ? route('finance.petty-cash.edit', $voucher) : null);
        }
        foreach (['submit', 'approve', 'void', 'post', 'reverse'] as $action) {
            if ($request->user()->hasPermission("finance.petty-cash.{$action}")) {
                $table->addColumn("{$action}_url", fn (PettyCashVoucher $voucher) => $this->actionAllowed($voucher, $action) ? route("finance.petty-cash.{$action}", $voucher) : null);
            }
        }

        return $table->toJson();
    }

    public function show(Request $request, PettyCashVoucher $voucher): View
    {
        $this->scopeVoucher($request, $voucher);

        return view('Finance::petty-cash.show', ['voucher' => $voucher->load(['fund.cashBankAccount', 'lines', 'journalEntry', 'reversalJournalEntry']), 'history' => AuditLog::query()->with('user')->where('subject_type', $voucher->getMorphClass())->where('subject_id', $voucher->id)->latest('created_at')->latest('id')->get(), 'dateFormat' => 'd/m/Y']);
    }

    public function store(SavePettyCashVoucherRequest $request, PettyCashVoucherService $service): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $voucher = $service->create($request->validated(), $warehouse, $this->sequence($warehouse), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'สร้างใบสำคัญเงินสดย่อยแล้ว', 'data' => $voucher, 'redirect' => route('finance.petty-cash.show', $voucher)], 201);
    }

    public function update(SavePettyCashVoucherRequest $request, PettyCashVoucher $voucher, PettyCashVoucherService $service): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $this->scopeVoucher($request, $voucher);

        $voucher = $service->update($voucher, $request->validated(), $warehouse, $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'บันทึกใบสำคัญเงินสดย่อยแล้ว', 'data' => $voucher, 'redirect' => route('finance.petty-cash.show', $voucher)]);
    }

    public function submit(PettyCashActionRequest $request, PettyCashVoucher $voucher, PettyCashVoucherService $service): JsonResponse
    {
        return $this->action($request, $voucher, $service, 'submit');
    }

    public function approve(PettyCashActionRequest $request, PettyCashVoucher $voucher, PettyCashVoucherService $service): JsonResponse
    {
        return $this->action($request, $voucher, $service, 'approve');
    }
    public function reject(PettyCashActionRequest $request, PettyCashVoucher $voucher, PettyCashVoucherService $service): JsonResponse { $this->scopeVoucher($request, $voucher); $result = $service->reject($voucher, $request->attributes->get('selectedWarehouse'), (string) $request->validated()['reason'], $request->user(), $request); return response()->json(['status' => true, 'msg' => 'ไม่อนุมัติใบสำคัญแล้ว', 'data' => $result]); }
    public function destroy(Request $request, PettyCashVoucher $voucher, PettyCashVoucherService $service): JsonResponse { $this->scopeVoucher($request, $voucher); $service->deleteDraft($voucher, $request->attributes->get('selectedWarehouse'), $request->user(), $request); return response()->json(['status' => true, 'msg' => 'ลบเอกสาร Draft แล้ว', 'redirect' => route('finance.petty-cash.index')]); }

    public function void(PettyCashActionRequest $request, PettyCashVoucher $voucher, PettyCashVoucherService $service): JsonResponse
    {
        return $this->action($request, $voucher, $service, 'void');
    }

    public function post(PettyCashActionRequest $request, PettyCashVoucher $voucher, PettyCashVoucherService $service): JsonResponse
    {
        return $this->action($request, $voucher, $service, 'post');
    }

    public function reverse(PettyCashActionRequest $request, PettyCashVoucher $voucher, PettyCashVoucherService $service): JsonResponse
    {
        return $this->action($request, $voucher, $service, 'reverse');
    }

    private function action(PettyCashActionRequest $request, PettyCashVoucher $voucher, PettyCashVoucherService $service, string $action): JsonResponse
    {
        $this->scopeVoucher($request, $voucher);
        $warehouse = $request->attributes->get('selectedWarehouse');
        $values = $request->validated();
        $result = match ($action) {
            'submit' => $service->submit($voucher, $warehouse, $request->user(), $request),
            'approve' => $service->approve($voucher, $warehouse, $request->user(), $request),
            'void' => $service->void($voucher, $warehouse, (string) ($values['reason'] ?? ''), $request->user(), $request),
            'post' => $service->post($voucher, $warehouse, $request->user(), $request),
            'reverse' => $service->reverse($voucher, $warehouse, (string) $values['reversal_date'], (string) ($values['reason'] ?? ''), $request->user(), $request),
        };

        return response()->json(['status' => true, 'msg' => 'อัปเดตสถานะใบสำคัญเงินสดย่อยแล้ว', 'data' => $result]);
    }

    private function sequence(Warehouse $warehouse): DocumentSequence
    {
        return DocumentSequence::query()->where('document_type', 'PETTY_CASH')->where('is_active', true)
            ->where(fn (Builder $query) => $query->where('warehouse_id', $warehouse->id)->orWhereNull('warehouse_id'))
            ->orderByRaw('warehouse_id IS NULL')->first()
            ?? throw ValidationException::withMessages(['document_sequence' => 'ยังไม่ได้ตั้งค่าเลขเอกสาร PETTY_CASH สำหรับคลังนี้']);
    }

    private function options(Request $request): array
    {
        $warehouseIds = $this->warehouseIds($request);
        $funds = PettyCashFund::query()->with('cashBankAccount')->whereIn('warehouse_id', $warehouseIds)->where('is_active', true)->orderBy('id')->get();

        return [
            'fundOptions' => $funds->mapWithKeys(fn (PettyCashFund $fund) => [$fund->id => $fund->name.' · '.($fund->cashBankAccount?->code ?? 'CASH')])->all(),
            'expenseCategoryOptions' => OtherCategory::query()->where('kind', 'EXPENSE')->where('is_active', true)->orderBy('code')->get()->mapWithKeys(fn (OtherCategory $category) => [$category->id => "{$category->code} · {$category->name}"])->all(),
            'taxCodeOptions' => TaxCode::query()->where('is_active', true)->whereIn('kind', ['VAT_IN', 'NONE_VAT', 'WHT'])->orderBy('kind')->orderBy('code')->get(['id', 'code', 'name', 'kind', 'rate'])->map(fn (TaxCode $tax) => ['id' => $tax->id, 'code' => $tax->code, 'name' => $tax->name, 'kind' => $tax->kind, 'rate' => (float) $tax->rate])->values()->all(),
            'payeeUserOptions' => User::query()->orderBy('name')->pluck('name', 'id')->all(),
            'payeeSupplierOptions' => Party::query()->where('is_active', true)->whereHas('roles', fn (Builder $query) => $query->where('role', 'SUPPLIER')->where('is_active', true))->orderBy('name')->pluck('name', 'id')->all(),
        ];
    }

    /** @return list<int> */
    private function warehouseIds(Request $request): array
    {
        return $request->user()->warehouses()->where('is_active', true)->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
    }

    private function scopeVoucher(Request $request, PettyCashVoucher $voucher): void
    {
        abort_unless((int) $voucher->warehouse_id === (int) $request->attributes->get('selectedWarehouse')->id, 404);
    }

    private function actionAllowed(PettyCashVoucher $voucher, string $action): bool
    {
        return match ($action) {
            'submit' => $voucher->status === 'DRAFT', 'approve' => $voucher->status === 'SUBMITTED',
            'void' => in_array($voucher->status, ['DRAFT', 'SUBMITTED', 'APPROVED'], true),
            'post' => $voucher->status === 'APPROVED', 'reverse' => $voucher->status === 'POSTED', default => false,
        };
    }

    private function search(Builder $query, Request $request, array $columns): void
    {
        $value = trim((string) $request->input('search.value'));
        if ($value !== '') {
            $query->where(fn (Builder $query) => collect($columns)->each(fn (string $column) => $query->orWhere($column, 'like', "%{$value}%")));
        }
    }
}
