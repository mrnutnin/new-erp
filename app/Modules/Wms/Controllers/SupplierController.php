<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\PartyRole;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\SalesDocument;
use App\Modules\Wms\Models\PurchaseDocument;
use App\Modules\Wms\Requests\SaveSupplierRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class SupplierController extends Controller
{
    protected function moduleRoutePrefix(): string
    {
        return 'wms';
    }

    protected function moduleViewPrefix(): string
    {
        return 'Wms';
    }

    public function index(): View
    {
        return view($this->moduleViewPrefix().'::suppliers.index', [
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
            'results' => $suppliers->take(30)->map(fn (Party $supplier) => ['id' => $supplier->id, 'text' => $supplier->code.' · '.$supplier->name])->values(),
            'pagination' => ['more' => $suppliers->count() > 30],
        ]);
    }

    public function create(): View
    {
        return view($this->moduleViewPrefix().'::suppliers.form', $this->formData(new Party([
            'type' => 'COMPANY',
            'branch_code' => '00000',
            'is_active' => true,
        ])) + ['moduleRoutePrefix' => $this->moduleRoutePrefix()]);
    }

    public function edit(Party $supplier): View
    {
        $supplier = $this->supplierParty($supplier);

        return view($this->moduleViewPrefix().'::suppliers.form', $this->formData($supplier) + [
            'moduleRoutePrefix' => $this->moduleRoutePrefix(),
        ]);
    }

    public function store(SaveSupplierRequest $request, AuditLogger $audit, DocumentSequenceService $sequences): JsonResponse
    {
        try {
            [, $attached] = DB::transaction(function () use ($request, $audit, $sequences) {
                $values = $request->safe()->except(['payment_term_id', 'credit_limit', 'is_active']);
                $sequence = null;
                if (blank($values['code'] ?? null)) {
                    $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where(['document_type' => 'SUPPLIER', 'is_active' => true])->lockForUpdate()->firstOrFail();
                    $values['code'] = $sequences->issueAvailable($sequence, now(), fn (string $code): bool => Party::withTrashed()->where('code', $code)->exists());
                }
                $supplier = Party::query()->withTrashed()->where('code', $values['code'])->lockForUpdate()->first();
                $attached = $supplier !== null;
                $before = $supplier ? $this->auditValues($supplier) : [];

                if ($supplier) {
                    $supplier->restore();
                    $supplier->update(['updated_by' => $request->user()->id]);
                } else {
                    $supplier = Party::query()->create([
                        ...$values,
                        'is_active' => true,
                        'created_by' => $request->user()->id,
                        'updated_by' => $request->user()->id,
                    ]);
                    if ($sequence) {
                        $sequences->recordIssued($sequence, $supplier->code, Party::class, $supplier->id, now(), $request->user()->id);
                    }
                }

                $role = $supplier->roles()->create([
                    'role' => 'SUPPLIER',
                    ...$request->safe()->only(['payment_term_id', 'credit_limit', 'is_active']),
                ]);
                $this->syncPartyActive($supplier);
                $audit->record('wms.supplier.created', $supplier, $before, $this->auditValues($supplier, $role), $request->user(), $request);

                return [$supplier, $attached];
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) !== 1062) {
                throw $exception;
            }

            throw ValidationException::withMessages(['code' => 'ข้อมูลคู่ค้าซ้ำกับรายการที่มีอยู่ กรุณาลองใหม่']);
        }

        return response()->json([
            'status' => true,
            'msg' => $attached ? 'เพิ่มบทบาท Supplier ให้คู่ค้าเดิมแล้ว' : 'เพิ่ม Supplier แล้ว',
            'redirect' => route($this->moduleRoutePrefix().'.suppliers.index'),
        ]);
    }

    public function update(SaveSupplierRequest $request, Party $supplier, AuditLogger $audit): JsonResponse
    {
        $supplier = $this->supplierParty($supplier);

        DB::transaction(function () use ($request, $supplier, $audit) {
            $supplier = Party::query()->lockForUpdate()->findOrFail($supplier->id);
            $role = PartyRole::query()->lockForUpdate()->where('party_id', $supplier->id)->where('role', 'SUPPLIER')->firstOrFail();

            if ($this->hasAnyFinancialHistory($supplier) && (
                $supplier->code !== $request->input('code')
                || $supplier->type !== $request->input('type')
                || $supplier->tax_id !== $request->input('tax_id')
                || $supplier->branch_code !== $request->input('branch_code')
            )) {
                throw ValidationException::withMessages([
                    'code' => 'ไม่สามารถเปลี่ยนรหัสหรือข้อมูลภาษีของ Supplier ที่มีประวัติทางการเงินได้',
                ]);
            }

            $before = $this->auditValues($supplier, $role);
            $supplier->update([
                ...$request->safe()->except(['payment_term_id', 'credit_limit', 'is_active']),
                'updated_by' => $request->user()->id,
            ]);
            $role->update($request->safe()->only(['payment_term_id', 'credit_limit', 'is_active']));
            $this->syncPartyActive($supplier);
            $audit->record('wms.supplier.updated', $supplier, $before, $this->auditValues($supplier, $role), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไข Supplier แล้ว']);
    }

    public function destroy(Request $request, Party $supplier, AuditLogger $audit): JsonResponse
    {
        $supplier = $this->supplierParty($supplier);

        $deleted = DB::transaction(function () use ($request, $supplier, $audit) {
            $supplier = Party::query()->lockForUpdate()->findOrFail($supplier->id);
            $role = PartyRole::query()->lockForUpdate()->where('party_id', $supplier->id)->where('role', 'SUPPLIER')->firstOrFail();

            if ($this->hasSupplierFinancialHistory($supplier)) {
                return false;
            }

            $before = $this->auditValues($supplier, $role);
            $role->delete();
            $supplier->update(['updated_by' => $request->user()->id]);

            if (! $supplier->roles()->exists()) {
                $supplier->delete();
            } else {
                $this->syncPartyActive($supplier);
            }

            $audit->record('wms.supplier.deleted', $supplier, $before, [
                'supplier_role_deleted' => true,
                'party_deleted_at' => $supplier->deleted_at,
            ], $request->user(), $request);

            return true;
        });

        if (! $deleted) {
            return response()->json([
                'status' => false,
                'msg' => 'ไม่สามารถลบ Supplier ที่มีประวัติเจ้าหนี้หรือเอกสารจ่ายเงินได้ กรุณาปิดใช้งานแทน',
            ], 422);
        }

        return response()->json(['status' => true, 'msg' => 'ลบ Supplier แล้ว']);
    }

    protected function formData(Party $supplier): array
    {
        $supplier->loadMissing('supplierRole');

        return [
            'supplier' => $supplier,
            'supplierRole' => $supplier->supplierRole ?? new PartyRole([
                'role' => 'SUPPLIER',
                'credit_limit' => '0.00',
                'is_active' => true,
            ]),
            'paymentTerms' => PaymentTerm::query()
                ->where(fn (Builder $query) => $query
                    ->where('is_active', true)
                    ->when($supplier->supplierRole?->payment_term_id, fn (Builder $query, int $id) => $query->orWhere('id', $id)))
                ->orderBy('code')
                ->get(['id', 'code', 'name']),
        ];
    }

    protected function suppliersQuery(): Builder
    {
        return Party::query()
            ->join('party_roles', fn ($join) => $join
                ->on('party_roles.party_id', '=', 'parties.id')
                ->where('party_roles.role', 'SUPPLIER'))
            ->leftJoin('finance_payment_terms', 'finance_payment_terms.id', '=', 'party_roles.payment_term_id')
            ->select([
                'parties.*',
                'party_roles.credit_limit',
                'party_roles.is_active as role_is_active',
                'finance_payment_terms.code as payment_term_code',
                'finance_payment_terms.name as payment_term_name',
            ]);
    }

    protected function applyTableSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('parties.code', 'like', "%{$search}%")
                ->orWhere('parties.name', 'like', "%{$search}%")
                ->orWhere('parties.tax_id', 'like', "%{$search}%")
                ->orWhere('parties.branch_code', 'like', "%{$search}%")
                ->orWhere('parties.contact_name', 'like', "%{$search}%")
                ->orWhere('parties.phone', 'like', "%{$search}%")
                ->orWhere('parties.email', 'like', "%{$search}%")
                ->orWhere('finance_payment_terms.code', 'like', "%{$search}%")
                ->orWhere('finance_payment_terms.name', 'like', "%{$search}%"));
        }
    }

    protected function applyTableOrder(Builder $query, Request $request): void
    {
        $columns = [
            0 => 'parties.code',
            1 => 'parties.name',
            2 => 'parties.type',
            3 => 'parties.tax_id',
            4 => 'parties.contact_name',
            5 => 'finance_payment_terms.code',
            6 => 'party_roles.credit_limit',
            7 => 'party_roles.is_active',
        ];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'parties.code';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $query->reorder($column, $direction)->orderBy('parties.id');
    }

    private function auditValues(Party $supplier, ?PartyRole $role = null): array
    {
        return [
            ...$supplier->only([
                'code', 'name', 'type', 'tax_id', 'branch_code', 'contact_name', 'phone', 'email',
                'address', 'is_active',
            ]),
            'supplier_payment_term_id' => $role?->payment_term_id,
            'supplier_credit_limit' => $role?->credit_limit,
            'supplier_is_active' => $role?->is_active,
        ];
    }

    private function hasSupplierFinancialHistory(Party $supplier): bool
    {
        $identifiers = [(string) $supplier->id, $supplier->code];

        return OpenItem::query()->where('party_type', 'SUPPLIER')->whereIn('party_id', $identifiers)->exists()
            || Settlement::query()->withTrashed()->where('party_type', 'SUPPLIER')->whereIn('party_id', $identifiers)->exists()
            || PurchaseDocument::query()->where('supplier_id', $supplier->id)->where('status', '!=', 'VOID')->exists();
    }

    private function hasAnyFinancialHistory(Party $supplier): bool
    {
        $identifiers = [(string) $supplier->id, $supplier->code];

        return OpenItem::query()->whereIn('party_id', $identifiers)->exists()
            || Settlement::query()->withTrashed()->whereIn('party_id', $identifiers)->exists()
            || SalesDocument::query()->where('party_id', $supplier->id)->where('status', '!=', 'VOID')->exists()
            || PurchaseDocument::query()->where('supplier_id', $supplier->id)->where('status', '!=', 'VOID')->exists();
    }

    private function syncPartyActive(Party $supplier): void
    {
        $supplier->update(['is_active' => $supplier->roles()->where('is_active', true)->exists()]);
    }

    private function supplierParty(Party $supplier): Party
    {
        abort_unless($supplier->supplierRole()->exists(), 404);

        return $supplier;
    }
}
