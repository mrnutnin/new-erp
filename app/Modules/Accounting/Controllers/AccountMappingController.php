<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\AccountMapping;
use App\Modules\Accounting\Requests\SaveAccountMappingRequest;
use App\Modules\Accounting\Services\AccountMappingService;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AccountMappingController extends Controller
{
    public function index(): View
    {
        return view('Accounting::account-mappings.index');
    }

    public function data(Request $request, AccountMappingService $mappings): JsonResponse
    {
        $dataTable = DataTables::eloquent($this->mappingsQuery())
            ->filter(fn (Builder $query) => $this->applySearch($query, $request, $mappings))
            ->order(fn (Builder $query) => $this->applyOrder($query, $request))
            ->addColumn('key_label', fn (AccountMapping $mapping) => $mappings->label($mapping->key))
            ->addColumn('account_label', fn (AccountMapping $mapping) => $mapping->account_code.' · '.$mapping->account_name);

        if ($request->user()->hasPermission('accounting.account-mappings.update')) {
            $dataTable->addColumn('edit_url', fn (AccountMapping $mapping) => route('accounting.account-mappings.edit', $mapping));
        }

        return $dataTable->toJson();
    }

    public function accountOptions(Request $request, AccountMappingService $mappings): JsonResponse
    {
        $values = $request->validate(['key' => ['required', Rule::in($mappings->keys())], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);
        $search = trim((string) ($values['q'] ?? ''));
        $page = max(1, (int) ($values['page'] ?? 1));
        $accounts = Account::query()->with('type:id,code')
            ->where('is_active', true)->where('is_postable', true)
            ->when($values['key'] === 'SALES_AR', fn (Builder $query) => $query->where('control_account_type', 'AR'))
            ->when($values['key'] === 'PURCHASE_AP', fn (Builder $query) => $query->where('control_account_type', 'AP'))
            ->when($values['key'] === 'CUSTOMER_ADVANCE', fn (Builder $query) => $query->whereNull('control_account_type')->whereHas('type', fn (Builder $query) => $query->where('code', 'LIABILITY')))
            ->when($values['key'] === 'SUPPLIER_ADVANCE', fn (Builder $query) => $query->whereNull('control_account_type')->whereHas('type', fn (Builder $query) => $query->where('code', 'ASSET')))
            ->when($values['key'] === 'SALES_REVENUE_DEFAULT', fn (Builder $query) => $query->whereNull('control_account_type')->whereHas('type', fn (Builder $query) => $query->where('code', 'REVENUE')))
            ->when($values['key'] === 'PURCHASE_EXPENSE_DEFAULT', fn (Builder $query) => $query->whereNull('control_account_type')->whereHas('type', fn (Builder $query) => $query->whereIn('code', ['EXPENSE', 'ASSET'])))
            ->when(in_array($values['key'], ['DEFERRED_INPUT_VAT', 'INPUT_VAT'], true), fn (Builder $query) => $query->where('control_account_type', 'INPUT_VAT'))
            ->when(in_array($values['key'], ['DEFERRED_OUTPUT_VAT', 'OUTPUT_VAT'], true), fn (Builder $query) => $query->where('control_account_type', 'OUTPUT_VAT'))
            ->when(in_array($values['key'], ['WHT_RECEIVABLE', 'WHT_PAYABLE'], true), fn (Builder $query) => $query->where('control_account_type', 'WITHHOLDING_TAX'))
            ->when($values['key'] === 'INVENTORY_DEFAULT', fn (Builder $query) => $query->where('control_account_type', 'INVENTORY'))
            ->when(in_array($values['key'], ['COGS_DEFAULT', 'INVENTORY_ADJUSTMENT_LOSS', 'INVENTORY_RECOST_LOSS'], true), fn (Builder $query) => $query->whereNull('control_account_type')->whereHas('type', fn (Builder $query) => $query->where('code', 'EXPENSE')))
            ->when(in_array($values['key'], ['INVENTORY_ADJUSTMENT_GAIN', 'INVENTORY_RECOST_GAIN'], true), fn (Builder $query) => $query->whereNull('control_account_type')->whereHas('type', fn (Builder $query) => $query->where('code', 'REVENUE')))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')->forPage($page, 31)->get(['id', 'account_type_id', 'code', 'name']);

        return response()->json([
            'results' => $accounts->take(30)->map(fn (Account $account) => ['id' => $account->id, 'text' => $account->code.' · '.$account->name])->values(),
            'pagination' => ['more' => $accounts->count() > 30],
        ]);
    }

    public function create(AccountMappingService $mappings): View
    {
        $used = AccountMapping::query()->pluck('key')->all();

        return view('Accounting::account-mappings.form', [
            'accountMapping' => new AccountMapping(['is_active' => true]),
            'availableKeys' => collect($mappings->keys())->reject(fn (string $key) => in_array($key, $used, true))->mapWithKeys(fn (string $key) => [$key => $mappings->label($key)]),
            'selectedAccount' => null,
        ]);
    }

    public function edit(AccountMapping $accountMapping, AccountMappingService $mappings): View
    {
        return view('Accounting::account-mappings.form', [
            'accountMapping' => $accountMapping,
            'availableKeys' => collect([$accountMapping->key => $mappings->label($accountMapping->key)]),
            'selectedAccount' => Account::query()->find($accountMapping->account_id, ['id', 'code', 'name']),
        ]);
    }

    public function store(SaveAccountMappingRequest $request, AuditLogger $audit, AccountMappingService $mappings): JsonResponse
    {
        $mapping = DB::transaction(function () use ($request, $audit, $mappings) {
            $account = Account::query()->withTrashed()->with('type')->whereKey($request->integer('account_id'))->sharedLock()->firstOrFail();
            $mappings->assertCompatible($request->validated('key'), $account);
            $mapping = AccountMapping::query()->create([...$request->validated(), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $audit->record('accounting.account_mapping.created', $mapping, [], $mapping->only(['key', 'account_id', 'is_active']), $request->user(), $request);

            return $mapping;
        });

        return response()->json(['status' => true, 'msg' => 'เพิ่ม Account Mapping แล้ว', 'redirect' => route('accounting.account-mappings.index')]);
    }

    public function update(SaveAccountMappingRequest $request, AccountMapping $accountMapping, AuditLogger $audit, AccountMappingService $mappings): JsonResponse
    {
        DB::transaction(function () use ($request, $accountMapping, $audit, $mappings) {
            $mapping = AccountMapping::query()->lockForUpdate()->findOrFail($accountMapping->id);
            $account = Account::query()->withTrashed()->with('type')->whereKey($request->integer('account_id'))->sharedLock()->firstOrFail();
            $mappings->assertCompatible($request->validated('key'), $account);
            $before = $mapping->only(['key', 'account_id', 'is_active']);
            $mapping->update([...$request->validated(), 'updated_by' => $request->user()->id]);
            $audit->record('accounting.account_mapping.updated', $mapping, $before, $mapping->only(array_keys($before)), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไข Account Mapping แล้ว']);
    }

    private function mappingsQuery(): Builder
    {
        return AccountMapping::query()->join('accounts', 'accounts.id', '=', 'accounting_account_mappings.account_id')->select(['accounting_account_mappings.*', 'accounts.code as account_code', 'accounts.name as account_name']);
    }

    private function applySearch(Builder $query, Request $request, AccountMappingService $mappings): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $matchingKeys = collect($mappings->keys())->filter(fn (string $key) => str_contains(mb_strtolower($mappings->label($key)), mb_strtolower($search)));
            $query->where(fn (Builder $query) => $query->where('accounting_account_mappings.key', 'like', "%{$search}%")->orWhereIn('accounting_account_mappings.key', $matchingKeys)->orWhere('accounts.code', 'like', "%{$search}%")->orWhere('accounts.name', 'like', "%{$search}%"));
        }
    }

    private function applyOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 'accounting_account_mappings.key', 1 => 'accounts.code', 2 => 'accounting_account_mappings.is_active'];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? $columns[0];
        $query->reorder($column, $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc')->orderBy('accounting_account_mappings.id');
    }
}
