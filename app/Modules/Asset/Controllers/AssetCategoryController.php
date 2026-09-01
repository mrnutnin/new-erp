<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Asset\Models\AssetCategory;
use App\Modules\Asset\Requests\SaveAssetCategoryRequest;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AssetCategoryController extends Controller
{
    private const ACCOUNT_TYPES = [
        'asset_account_id' => 'ASSET',
        'accumulated_depreciation_account_id' => 'ASSET',
        'depreciation_expense_account_id' => 'EXPENSE',
        'accumulated_impairment_account_id' => 'ASSET',
        'impairment_loss_account_id' => 'EXPENSE',
        'disposal_gain_account_id' => 'REVENUE',
        'disposal_loss_account_id' => 'EXPENSE',
        'disposal_clearing_account_id' => 'ASSET',
    ];

    public function index(): View
    {
        return view('Asset::categories.index');
    }

    public function data(Request $request): JsonResponse
    {
        $table = DataTables::eloquent($this->query())
            ->filter(fn (Builder $query) => $this->search($query, $request))
            ->order(fn (Builder $query) => $this->order($query, $request));

        if ($request->user()->hasPermission('asset.categories.manage')) {
            $table->addColumn('edit_url', fn (AssetCategory $category) => route('asset.categories.edit', $category));
            $table->addColumn('delete_url', fn (AssetCategory $category) => route('asset.categories.destroy', $category));
        }

        return $table->toJson();
    }

    public function create(): View
    {
        return $this->form(new AssetCategory(['is_depreciable' => true, 'is_active' => true]));
    }

    public function edit(AssetCategory $assetCategory): View
    {
        return $this->form($assetCategory);
    }

    public function accountOptions(Request $request): JsonResponse
    {
        $field = (string) $request->input('field');
        abort_unless(isset(self::ACCOUNT_TYPES[$field]), 404);

        $search = trim((string) $request->input('q', ''));
        $page = max(1, $request->integer('page', 1));
        $accounts = Account::query()
            ->join('account_types', 'account_types.id', '=', 'accounts.account_type_id')
            ->where('accounts.is_active', true)->where('accounts.is_postable', true)
            ->where('account_types.code', self::ACCOUNT_TYPES[$field])
            ->when($field === 'asset_account_id', fn (Builder $query) => $query->where('accounts.control_account_type', 'FIXED_ASSET'))
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q->where('accounts.code', 'like', "%{$search}%")->orWhere('accounts.name', 'like', "%{$search}%")))
            ->orderBy('accounts.code')->forPage($page, 31)->get(['accounts.id', 'accounts.code', 'accounts.name']);

        return response()->json([
            'results' => $accounts->take(30)->map(fn (Account $account) => ['id' => $account->id, 'text' => $account->code.' · '.$account->name])->values(),
            'pagination' => ['more' => $accounts->count() > 30],
        ]);
    }

    public function store(SaveAssetCategoryRequest $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $category = DB::transaction(function () use ($request, $audit) {
            $category = AssetCategory::query()->create([...$request->validated(), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $audit->record('asset.category.created', $category, [], $this->snapshot($category), $request->user(), $request);

            return $category;
        });

        return $this->saved($request, 'เพิ่มหมวดสินทรัพย์แล้ว', $category);
    }

    public function update(SaveAssetCategoryRequest $request, AssetCategory $assetCategory, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        DB::transaction(function () use ($request, $assetCategory, $audit) {
            $category = AssetCategory::query()->lockForUpdate()->findOrFail($assetCategory->id);
            $before = $this->snapshot($category);
            $category->update([...$request->validated(), 'updated_by' => $request->user()->id]);
            $audit->record('asset.category.updated', $category, $before, $this->snapshot($category), $request->user(), $request);
        });

        return $this->saved($request, 'แก้ไขหมวดสินทรัพย์แล้ว', $assetCategory);
    }

    public function destroy(Request $request, AssetCategory $assetCategory, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $assetCategory, $audit) {
            $category = AssetCategory::query()->lockForUpdate()->findOrFail($assetCategory->id);
            if ($category->assets()->exists()) {
                throw ValidationException::withMessages(['category' => 'หมวดนี้ถูกใช้งานแล้ว ให้ปิดใช้งานแทนการลบ']);
            }

            $before = $this->snapshot($category);
            $category->delete();
            $audit->record('asset.category.deleted', $category, $before, ['deleted_at' => $category->deleted_at], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบหมวดสินทรัพย์แล้ว']);
    }

    private function form(AssetCategory $assetCategory): View
    {
        $selectedAccounts = Account::query()->whereIn('id', array_filter([
            $assetCategory->asset_account_id, $assetCategory->accumulated_depreciation_account_id, $assetCategory->depreciation_expense_account_id,
            $assetCategory->accumulated_impairment_account_id, $assetCategory->impairment_loss_account_id, $assetCategory->disposal_gain_account_id,
            $assetCategory->disposal_loss_account_id,
            $assetCategory->disposal_clearing_account_id,
        ]))->get(['id', 'code', 'name'])->keyBy('id');

        return view('Asset::categories.form', compact('assetCategory', 'selectedAccounts'));
    }

    private function query(): Builder
    {
        return AssetCategory::query()->select(['id', 'code', 'name', 'is_depreciable', 'capitalization_threshold', 'is_active']);
    }

    private function search(Builder $query, Request $request): void
    {
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
        }
    }

    private function order(Builder $query, Request $request): void
    {
        $columns = [0 => 'id', 1 => 'code', 2 => 'name', 3 => 'is_depreciable', 4 => 'capitalization_threshold', 5 => 'is_active'];
        $query->orderBy($columns[(int) $request->input('order.0.column', 0)] ?? 'code', $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc')->orderBy('id');
    }

    private function saved(SaveAssetCategoryRequest $request, string $message, AssetCategory $category): JsonResponse|RedirectResponse
    {
        $redirect = route('asset.categories.edit', $category);

        return $request->expectsJson() ? response()->json(['status' => true, 'msg' => $message, 'redirect' => $redirect]) : redirect($redirect)->with('success', $message);
    }

    private function snapshot(AssetCategory $category): array
    {
        return $category->only(['code', 'name', 'description', 'is_depreciable', 'capitalization_threshold', 'book_method', 'book_useful_life_months', 'book_residual_value_percent', 'tax_method', 'tax_useful_life_months', 'tax_rate_percent', 'tax_cost_cap', 'asset_account_id', 'accumulated_depreciation_account_id', 'depreciation_expense_account_id', 'accumulated_impairment_account_id', 'impairment_loss_account_id', 'disposal_gain_account_id', 'disposal_loss_account_id', 'disposal_clearing_account_id', 'is_active']);
    }
}
