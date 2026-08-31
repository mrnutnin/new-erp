<?php

namespace App\Modules\Purchasing\Controllers;

use App\Models\Party;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Wms\Requests\SaveSupplierRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

/**
 * Purchasing entry point for the Supplier flow.
 *
 * The implementation is deliberately inherited during the extraction wave;
 * this gives the canonical module its own controller seam without copying
 * identity, tax, audit, or financial-history rules. The parent can be
 * replaced method-by-method once the Purchasing request/view contracts move.
 */
class SupplierController extends \App\Modules\Wms\Controllers\SupplierController
{
    protected function moduleRoutePrefix(): string
    {
        return 'purchasing';
    }

    protected function moduleViewPrefix(): string
    {
        return 'Purchasing';
    }

    public function index(): View
    {
        return view('Purchasing::suppliers.index', [
            'moduleRoutePrefix' => $this->moduleRoutePrefix(),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $dataTable = DataTables::eloquent($this->suppliersQuery())
            ->filter(fn (Builder $query) => $this->applyTableSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyTableOrder($query, $request))
            ->addColumn('type_label', fn (Party $supplier) => $supplier->type === 'COMPANY' ? 'นิติบุคคล' : 'บุคคลธรรมดา')
            ->addColumn('tax_label', fn (Party $supplier) => $supplier->tax_id ? $supplier->tax_id.' · สาขา '.$supplier->branch_code : '—')
            ->addColumn('contact_label', fn (Party $supplier) => collect([$supplier->contact_name, $supplier->phone, $supplier->email])->filter()->implode(' · ') ?: '—')
            ->addColumn('payment_term_label', fn (Party $supplier) => $supplier->payment_term_code ? $supplier->payment_term_code.' · '.$supplier->payment_term_name : '—')
            ->addColumn('supplier_is_active', fn (Party $supplier) => $supplier->is_active && $supplier->role_is_active);

        if ($request->user()->hasPermission('wms.suppliers.update')) {
            $dataTable->addColumn('edit_url', fn (Party $supplier) => route($this->moduleRoutePrefix().'.suppliers.edit', $supplier));
        }

        if ($request->user()->hasPermission('wms.suppliers.delete')) {
            $dataTable->addColumn('delete_url', fn (Party $supplier) => route($this->moduleRoutePrefix().'.suppliers.destroy', $supplier));
        }

        return $dataTable->toJson();
    }

    public function options(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $page = max(1, $request->integer('page', 1));
        $suppliers = Party::query()
            ->join('party_roles', fn ($join) => $join
                ->on('party_roles.party_id', '=', 'parties.id')
                ->where('party_roles.role', 'SUPPLIER')
                ->where('party_roles.is_active', true))
            ->where('parties.is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('parties.code', 'like', "%{$search}%")
                ->orWhere('parties.name', 'like', "%{$search}%")
                ->orWhere('parties.tax_id', 'like', "%{$search}%")
                ->orWhere('parties.phone', 'like', "%{$search}%")))
            ->orderBy('parties.code')
            ->forPage($page, 31)
            ->get(['parties.id', 'parties.code', 'parties.name']);

        return response()->json([
            'results' => $suppliers->take(30)->map(fn (Party $supplier) => [
                'id' => $supplier->id,
                'text' => $supplier->code.' · '.$supplier->name,
            ])->values(),
            'pagination' => ['more' => $suppliers->count() > 30],
        ]);
    }

    public function create(): View
    {
        return view('Purchasing::suppliers.form', $this->formData(new Party([
            'type' => 'COMPANY',
            'branch_code' => '00000',
            'is_active' => true,
        ])) + ['moduleRoutePrefix' => $this->moduleRoutePrefix()]);
    }

    public function edit(Party $supplier): View
    {
        abort_unless($supplier->supplierRole()->exists(), 404);

        return view('Purchasing::suppliers.form', $this->formData($supplier) + [
            'moduleRoutePrefix' => $this->moduleRoutePrefix(),
        ]);
    }

    public function store(SaveSupplierRequest $request, AuditLogger $audit, DocumentSequenceService $sequences): JsonResponse
    {
        return parent::store($request, $audit, $sequences);
    }

    public function update(SaveSupplierRequest $request, Party $supplier, AuditLogger $audit): JsonResponse
    {
        return parent::update($request, $supplier, $audit);
    }

    public function destroy(Request $request, Party $supplier, AuditLogger $audit): JsonResponse
    {
        return parent::destroy($request, $supplier, $audit);
    }
}
