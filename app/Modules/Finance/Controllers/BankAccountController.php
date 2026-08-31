<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Finance\Models\BankAccount;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Requests\SaveBankAccountRequest;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class BankAccountController extends Controller
{
    public function index(): View
    {
        return view('Finance::bank-accounts.index');
    }

    public function data(Request $request): JsonResponse
    {
        $dataTable = DataTables::eloquent($this->bankAccountsQuery($request))
            ->filter(fn (Builder $query) => $this->applyTableSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyTableOrder($query, $request))
            ->addColumn('type_label', fn (BankAccount $bankAccount) => ['CASH' => 'เงินสด', 'BANK' => 'ธนาคาร', 'CREDIT_CARD' => 'บัตรเครดิต', 'CHEQUE' => 'เช็ค'][$bankAccount->type] ?? $bankAccount->type)
            ->addColumn('bank_details', fn (BankAccount $bankAccount) => collect([$bankAccount->bank_name, $bankAccount->account_number])->filter()->implode(' · ') ?: '—')
            ->addColumn('account_label', fn (BankAccount $bankAccount) => $bankAccount->account->code.' · '.$bankAccount->account->name);

        if ($request->user()->hasPermission('finance.bank-accounts.update')) {
            $dataTable->addColumn('edit_url', fn (BankAccount $bankAccount) => route('finance.bank-accounts.edit', $bankAccount));
        }

        if ($request->user()->hasPermission('finance.bank-accounts.delete')) {
            $dataTable->addColumn('delete_url', fn (BankAccount $bankAccount) => route('finance.bank-accounts.destroy', $bankAccount));
        }

        return $dataTable->toJson();
    }

    public function create(): View
    {
        return view('Finance::bank-accounts.form', ['bankAccount' => new BankAccount(['type' => 'BANK', 'currency_code' => 'THB', 'is_active' => true]), 'selectedAccount' => null]);
    }

    public function accountOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $type = in_array($request->input('type'), ['CASH', 'BANK', 'CREDIT_CARD', 'CHEQUE'], true) ? $request->input('type') : 'BANK';
        $page = max(1, $request->integer('page', 1));
        $accounts = Account::query()->where('control_account_type', $type)->where('is_active', true)->where('is_postable', true)->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))->orderBy('code')->forPage($page, 31)->get(['id', 'code', 'name']);

        return response()->json([
            'results' => $accounts->take(30)->map(fn (Account $account) => ['id' => $account->id, 'text' => $account->code.' · '.$account->name])->values(),
            'pagination' => ['more' => $accounts->count() > 30],
        ]);
    }

    public function store(SaveBankAccountRequest $request, AuditLogger $audit): JsonResponse
    {
        $warehouse = $request->attributes->get('selectedWarehouse');
        $account = DB::transaction(function () use ($request, $warehouse, $audit) {
            $account = BankAccount::create([...$request->validated(), 'warehouse_id' => $warehouse->id, 'created_by' => $request->user()->id]);
            $audit->record('finance.bank_account.created', $account, [], $account->only(['code', 'name', 'type', 'account_id', 'is_active']), $request->user(), $request);

            return $account;
        });

        return response()->json(['status' => true, 'msg' => 'เพิ่มบัญชีเงินสด/ธนาคารแล้ว', 'redirect' => route('finance.bank-accounts.index')]);
    }

    public function edit(Request $request, BankAccount $bankAccount): View
    {
        $bankAccount = $this->forSelectedWarehouse($request, $bankAccount);

        return view('Finance::bank-accounts.form', [
            'bankAccount' => $bankAccount,
            'selectedAccount' => Account::query()->find($bankAccount->account_id, ['id', 'code', 'name']),
        ]);
    }

    public function update(SaveBankAccountRequest $request, BankAccount $bankAccount, AuditLogger $audit): JsonResponse
    {
        $bankAccount = $this->forSelectedWarehouse($request, $bankAccount);

        DB::transaction(function () use ($request, $bankAccount, $audit) {
            $before = $bankAccount->only(['code', 'name', 'type', 'account_id', 'bank_name', 'account_number', 'currency_code', 'is_active']);
            $bankAccount->update($request->validated());
            $audit->record('finance.bank_account.updated', $bankAccount, $before, $bankAccount->only(array_keys($before)), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขบัญชีเงินสด/ธนาคารแล้ว']);
    }

    public function destroy(Request $request, BankAccount $bankAccount, AuditLogger $audit): JsonResponse
    {
        $bankAccount = $this->forSelectedWarehouse($request, $bankAccount);

        if (Settlement::query()->withTrashed()->where('bank_account_id', $bankAccount->id)->exists()) {
            return response()->json(['status' => false, 'msg' => 'ไม่สามารถลบบัญชีที่ถูกใช้งานในเอกสารรับเงิน/จ่ายเงินได้ กรุณาปิดใช้งานแทน'], 422);
        }

        DB::transaction(function () use ($bankAccount, $audit, $request) {
            $before = $bankAccount->only(['code', 'name', 'type', 'account_id', 'bank_name', 'account_number', 'currency_code', 'is_active']);
            $bankAccount->delete();
            $audit->record('finance.bank_account.deleted', $bankAccount, $before, ['deleted_at' => $bankAccount->deleted_at], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบบัญชีเงินสด/ธนาคารแล้ว']);
    }

    private function forSelectedWarehouse(Request $request, BankAccount $bankAccount): BankAccount
    {
        abort_unless($bankAccount->warehouse_id === $request->attributes->get('selectedWarehouse')->id, 404);

        return $bankAccount;
    }

    private function bankAccountsQuery(Request $request): Builder
    {
        return BankAccount::query()
            ->select([
                'finance_bank_accounts.id', 'finance_bank_accounts.account_id', 'finance_bank_accounts.type',
                'finance_bank_accounts.code', 'finance_bank_accounts.name', 'finance_bank_accounts.bank_name',
                'finance_bank_accounts.account_number', 'finance_bank_accounts.currency_code', 'finance_bank_accounts.is_active',
            ])
            ->with('account:id,code,name')
            ->where('finance_bank_accounts.warehouse_id', $request->attributes->get('selectedWarehouse')->id);
    }

    private function applyTableSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('finance_bank_accounts.code', 'like', "%{$search}%")
                ->orWhere('finance_bank_accounts.name', 'like', "%{$search}%")
                ->orWhere('finance_bank_accounts.bank_name', 'like', "%{$search}%")
                ->orWhere('finance_bank_accounts.account_number', 'like', "%{$search}%")
                ->orWhere('finance_bank_accounts.currency_code', 'like', "%{$search}%")
                ->orWhereHas('account', fn (Builder $query) => $query
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")));
        }
    }

    private function applyTableOrder(Builder $query, Request $request): void
    {
        $columns = [
            0 => 'finance_bank_accounts.code',
            1 => 'finance_bank_accounts.name',
            2 => 'finance_bank_accounts.type',
            3 => 'finance_bank_accounts.bank_name',
            4 => 'finance_bank_accounts.account_id',
            5 => 'finance_bank_accounts.is_active',
        ];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'finance_bank_accounts.code';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $query->reorder($column, $direction)->orderBy('finance_bank_accounts.id');
    }
}
