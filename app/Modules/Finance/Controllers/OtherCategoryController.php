<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Finance\Models\OtherCategory;
use App\Modules\Finance\Requests\SaveOtherCategoryRequest;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class OtherCategoryController extends Controller
{
    public function index(): View
    {
        return view('Finance::other-categories.index');
    }

    public function data(Request $request): JsonResponse
    {
        $dataTable = DataTables::eloquent($this->categoriesQuery())
            ->filter(fn (Builder $query) => $this->applyTableSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyTableOrder($query, $request))
            ->addColumn('kind_label', fn (OtherCategory $category) => $category->kind === 'INCOME' ? 'รายได้' : 'รายจ่าย');

        if ($request->user()->hasPermission('finance.other-categories.update')) {
            $dataTable->addColumn('edit_url', fn (OtherCategory $category) => route('finance.other-categories.edit', $category));
        }

        if ($request->user()->hasPermission('finance.other-categories.delete')) {
            $dataTable->addColumn('delete_url', fn (OtherCategory $category) => route('finance.other-categories.destroy', $category));
        }

        return $dataTable->toJson();
    }

    public function create(): View
    {
        return view('Finance::other-categories.form', $this->formData(new OtherCategory(['kind' => 'INCOME', 'is_active' => true])));
    }

    public function edit(OtherCategory $otherCategory): View
    {
        return view('Finance::other-categories.form', $this->formData($otherCategory));
    }

    public function accountOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $normalBalance = $request->input('kind') === 'INCOME' ? 'CREDIT' : 'DEBIT';
        $page = max(1, $request->integer('page', 1));
        $accounts = Account::query()->where('statement_section', 'PROFIT_LOSS')->where('normal_balance', $normalBalance)->where('is_active', true)->where('is_postable', true)->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))->orderBy('code')->forPage($page, 31)->get(['id', 'code', 'name']);

        return response()->json([
            'results' => $accounts->take(30)->map(fn (Account $account) => ['id' => $account->id, 'text' => $account->code.' · '.$account->name])->values(),
            'pagination' => ['more' => $accounts->count() > 30],
        ]);
    }

    public function store(SaveOtherCategoryRequest $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $category = DB::transaction(function () use ($request, $audit) {
            $category = OtherCategory::create([...$request->validated(), 'created_by' => $request->user()->id]);
            $audit->record('finance.other_category.created', $category, [], $category->only(['kind', 'code', 'name', 'account_id', 'tax_code_id', 'is_active']), $request->user(), $request);

            return $category;
        });

        return $request->expectsJson()
            ? response()->json(['status' => true, 'msg' => 'เพิ่มรายการรายได้/รายจ่ายอื่นแล้ว', 'redirect' => route('finance.other-categories.index')])
            : redirect()->route('finance.other-categories.index');
    }

    public function update(SaveOtherCategoryRequest $request, OtherCategory $otherCategory, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        DB::transaction(function () use ($request, $otherCategory, $audit) {
            $category = OtherCategory::query()->lockForUpdate()->findOrFail($otherCategory->id);
            $before = $category->only(['kind', 'code', 'name', 'account_id', 'tax_code_id', 'is_active']);
            $category->update($request->validated());
            $audit->record('finance.other_category.updated', $category, $before, $category->only(array_keys($before)), $request->user(), $request);
        });

        return $request->expectsJson()
            ? response()->json(['status' => true, 'msg' => 'แก้ไขรายการรายได้/รายจ่ายอื่นแล้ว'])
            : redirect()->route('finance.other-categories.index');
    }

    public function destroy(Request $request, OtherCategory $otherCategory, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $otherCategory, $audit) {
            $category = OtherCategory::query()->lockForUpdate()->findOrFail($otherCategory->id);
            $before = $category->only(['kind', 'code', 'name', 'account_id', 'tax_code_id', 'is_active']);
            $category->delete();
            $audit->record('finance.other_category.deleted', $category, $before, ['deleted_at' => $category->deleted_at], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบรายการรายได้/รายจ่ายอื่นแล้ว']);
    }

    private function formData(OtherCategory $otherCategory): array
    {
        return [
            'otherCategory' => $otherCategory,
            'selectedAccount' => $otherCategory->account_id ? Account::query()->find($otherCategory->account_id, ['id', 'code', 'name']) : null,
            'taxCodes' => TaxCode::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name', 'kind']),
        ];
    }

    private function categoriesQuery(): Builder
    {
        return OtherCategory::query()
            ->join('accounts', 'accounts.id', '=', 'finance_other_categories.account_id')
            ->leftJoin('tax_codes', 'tax_codes.id', '=', 'finance_other_categories.tax_code_id')
            ->select([
                'finance_other_categories.*',
                'accounts.code as account_code',
                'accounts.name as account_name',
                'tax_codes.code as tax_code',
            ]);
    }

    private function applyTableSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('finance_other_categories.code', 'like', "%{$search}%")
                ->orWhere('finance_other_categories.name', 'like', "%{$search}%")
                ->orWhere('finance_other_categories.kind', 'like', "%{$search}%")
                ->orWhere('accounts.code', 'like', "%{$search}%")
                ->orWhere('accounts.name', 'like', "%{$search}%")
                ->orWhere('tax_codes.code', 'like', "%{$search}%"));
        }
    }

    private function applyTableOrder(Builder $query, Request $request): void
    {
        $columns = [
            0 => 'finance_other_categories.kind',
            1 => 'finance_other_categories.code',
            2 => 'finance_other_categories.name',
            3 => 'accounts.code',
            4 => 'tax_codes.code',
            5 => 'finance_other_categories.is_active',
        ];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'finance_other_categories.kind';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($column, $direction)->orderBy('finance_other_categories.code');
    }
}
