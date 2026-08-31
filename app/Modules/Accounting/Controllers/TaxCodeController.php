<?php

namespace App\Modules\Accounting\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Accounting\Requests\SaveTaxCodeRequest;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class TaxCodeController extends Controller
{
    public function index(): View
    {
        return view('Accounting::tax-codes.index');
    }

    public function data(Request $request): JsonResponse
    {
        $dataTable = DataTables::eloquent(TaxCode::query()->withTrashed())
            ->filter(fn (Builder $query) => $this->applySearch($query, $request))
            ->order(fn (Builder $query) => $this->applyOrder($query, $request))
            ->addColumn('kind_label', fn (TaxCode $tax) => ['VAT_IN' => 'VAT IN', 'VAT_OUT' => 'VAT OUT', 'NONE_VAT' => 'NONE VAT', 'WHT' => 'หัก ณ ที่จ่าย'][$tax->kind]);

        if ($request->user()->hasPermission('accounting.tax-codes.update')) {
            $dataTable->addColumn('edit_url', fn (TaxCode $tax) => route('accounting.tax-codes.edit', $tax));
        }

        if ($request->user()->hasPermission('accounting.tax-codes.delete')) {
            $dataTable->addColumn('delete_url', fn (TaxCode $tax) => $tax->deleted_at ? null : route('accounting.tax-codes.destroy', $tax));
        }

        return $dataTable->toJson();
    }

    public function create(): View
    {
        return view('Accounting::tax-codes.form', ['taxCode' => new TaxCode(['is_active' => true, 'rate' => 0])]);
    }

    public function edit(TaxCode $taxCode): View
    {
        return view('Accounting::tax-codes.form', compact('taxCode'));
    }

    public function store(SaveTaxCodeRequest $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $tax = DB::transaction(function () use ($request, $audit) {
            $tax = TaxCode::create([...$request->validated(), 'created_by' => $request->user()->id]);
            $audit->record('accounting.tax_code.created', $tax, [], $tax->only(['code', 'name', 'kind', 'rate', 'is_active']), $request->user(), $request);

            return $tax;
        });

        return $request->expectsJson() ? response()->json(['status' => true, 'msg' => 'เพิ่ม Tax Code แล้ว', 'redirect' => route('accounting.tax-codes.index')]) : redirect()->route('accounting.tax-codes.index');
    }

    public function update(SaveTaxCodeRequest $request, TaxCode $taxCode, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        DB::transaction(function () use ($request, $taxCode, $audit) {
            $tax = TaxCode::query()->lockForUpdate()->findOrFail($taxCode->id);
            $values = $request->validated();
            $before = $tax->only(array_keys($values));
            $tax->update($values);
            $audit->record('accounting.tax_code.updated', $tax, $before, $values, $request->user(), $request);
        });

        return $request->expectsJson() ? response()->json(['status' => true, 'msg' => 'แก้ไข Tax Code แล้ว']) : redirect()->route('accounting.tax-codes.index');
    }

    public function destroy(Request $request, TaxCode $taxCode, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $taxCode, $audit) {
            $tax = TaxCode::query()->lockForUpdate()->findOrFail($taxCode->id);
            $before = $tax->only(['code', 'name', 'kind', 'rate', 'is_active']);
            $tax->delete();
            $audit->record('accounting.tax_code.deleted', $tax, $before, ['deleted_at' => $tax->deleted_at], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบ Tax Code แล้ว']);
    }

    private function applySearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('code', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%"));
        }
    }

    private function applyOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 'code', 1 => 'name', 2 => 'kind', 3 => 'rate', 4 => 'is_active'];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'code';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $query->reorder($column, $direction)->orderBy('id');
    }
}
