<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\CustomerGroup;
use App\Models\Party;
use App\Models\PartyAddress;
use App\Models\PartyRole;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Models\OpenItem;
use App\Modules\Finance\Models\PaymentTerm;
use App\Modules\Finance\Models\Settlement;
use App\Modules\Finance\Services\DocumentSequenceService;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\SalesDocument;
use App\Modules\Pos\Requests\SaveCustomerRequest;
use App\Modules\Wms\Models\PurchaseDocument;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class CustomerController extends Controller
{
    public function index(): View
    {
        return view('Pos::customers.index');
    }

    public function data(Request $request): JsonResponse
    {
        $table = DataTables::eloquent($this->customersQuery())
            ->filter(fn (Builder $query) => $this->applySearch($query, $request))
            ->order(fn (Builder $query) => $this->applyOrder($query, $request))
            ->addColumn('type_label', fn (Party $customer) => $customer->type === 'COMPANY' ? 'นิติบุคคล' : 'บุคคลธรรมดา')
            ->addColumn('tax_label', fn (Party $customer) => collect([$customer->tax_id, $customer->tax_id ? 'สาขา '.$customer->branch_code : null])->filter()->implode(' · ') ?: '—')
            ->addColumn('contact_label', fn (Party $customer) => collect([$customer->contact_name, $customer->phone])->filter()->implode(' · ') ?: '—')
            ->addColumn('group_label', fn (Party $customer) => $customer->customer_group_name ?: '—')
            ->addColumn('payment_term_label', fn (Party $customer) => $customer->payment_term_code ? $customer->payment_term_code.' · '.$customer->payment_term_name : '—');

        if ($request->user()->hasPermission('pos.customers.update')) {
            $table->addColumn('edit_url', fn (Party $customer) => route('pos.customers.edit', $customer));
        }
        if ($request->user()->hasPermission('pos.customers.delete')) {
            $table->addColumn('delete_url', fn (Party $customer) => route('pos.customers.destroy', $customer));
        }

        return $table->toJson();
    }

    public function options(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $page = max(1, $request->integer('page', 1));
        $customers = Party::query()
            ->join('party_roles as customer_roles', function ($join) {
                $join->on('customer_roles.party_id', '=', 'parties.id')->where('customer_roles.role', 'CUSTOMER')->where('customer_roles.is_active', true);
            })
            ->where('parties.is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('parties.code', 'like', "%{$search}%")->orWhere('parties.name', 'like', "%{$search}%")
                ->orWhere('parties.tax_id', 'like', "%{$search}%")->orWhere('parties.phone', 'like', "%{$search}%")))
            ->orderBy('parties.code')->forPage($page, 31)->get(['parties.id', 'parties.code', 'parties.name']);

        return response()->json([
            'results' => $customers->take(30)->map(fn (Party $customer) => ['id' => $customer->id, 'text' => $customer->code.' · '.$customer->name])->values(),
            'pagination' => ['more' => $customers->count() > 30],
        ]);
    }

    public function quickOptions(Request $request): JsonResponse
    {
        $code = mb_strtoupper(trim((string) $request->input('code')));
        $name = trim((string) $request->input('name'));
        $taxId = preg_replace('/\D+/', '', (string) $request->input('tax_id'));
        $branchCode = trim((string) $request->input('branch_code', '00000'));
        $phone = trim((string) $request->input('phone'));
        $email = mb_strtolower(trim((string) $request->input('email')));

        if ($code === '' && $name === '' && $taxId === '' && $phone === '' && $email === '') {
            return response()->json(['results' => [], 'hard_match' => false]);
        }

        $customers = Party::withTrashed()->with('customerRole')->where(function (Builder $query) use ($code, $name, $taxId, $branchCode, $phone, $email) {
            if ($code !== '') {
                $query->orWhere('code', 'like', "%{$code}%");
            }
            if ($name !== '') {
                $query->orWhere('name', 'like', "%{$name}%");
            }
            if ($taxId !== '') {
                $query->orWhere('tax_id', $taxId)->where('branch_code', $branchCode);
            }
            if ($phone !== '') {
                $query->orWhere('phone', $phone);
            }
            if ($email !== '') {
                $query->orWhere('email', $email);
            }
        })->orderBy('code')->limit(5)->get(['id', 'code', 'name', 'tax_id', 'branch_code', 'phone', 'email', 'is_active', 'deleted_at']);

        $results = $customers->map(function (Party $customer) use ($code, $taxId, $branchCode) {
            $hardMatch = ($code !== '' && $customer->code === $code)
                || ($taxId !== '' && $customer->tax_id === $taxId && $customer->branch_code === $branchCode);
            $canSelect = ! $customer->trashed() && $customer->is_active && $customer->customerRole?->is_active;

            return ['id' => $customer->id, 'text' => $customer->code.' · '.$customer->name, 'hard_match' => $hardMatch, 'can_select' => $canSelect];
        })->values();

        return response()->json(['results' => $results, 'hard_match' => $results->contains('hard_match', true)]);
    }

    public function groupOptions(Request $request): JsonResponse
    {
        $search = trim((string) $request->input('q', ''));
        $groups = CustomerGroup::query()->forCompany($this->companySettingId())->where('is_active', true)
            ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")))
            ->orderBy('code')->limit(30)->get(['id', 'code', 'name']);

        return response()->json(['results' => $groups->map(fn (CustomerGroup $group) => [
            'id' => $group->id, 'text' => $group->code.' · '.$group->name,
        ])->values(), 'pagination' => ['more' => false]]);
    }

    public function create(): View
    {
        return $this->formView(new Party(['type' => 'COMPANY', 'branch_code' => '00000']), new PartyRole(['credit_limit' => '0.00', 'is_active' => true]));
    }

    public function edit(Party $customer): View
    {
        return $this->formView($customer, $this->customerRoleOrFail($customer));
    }

    public function store(SaveCustomerRequest $request, AuditLogger $audit, DocumentSequenceService $sequences): JsonResponse
    {
        try {
            [$customer, $attached] = DB::transaction(function () use ($request, $audit, $sequences) {
                $data = $request->validated();
                $customer = $this->matchingParty($data);
                $attached = $customer !== null;
                $before = $customer ? $this->auditValues($customer->load('customerRole')) : [];

                if ($customer) {
                    $data['code'] ??= $customer->code;
                    if ($customer->code !== $data['code']) {
                        throw ValidationException::withMessages(['code' => "เลขผู้เสียภาษีนี้เป็นคู่ค้ารหัส {$customer->code} กรุณาใช้รหัสเดิม"]);
                    }
                    if ($customer->customerRole()->exists()) {
                        throw ValidationException::withMessages(['code' => 'คู่ค้านี้มีบทบาทลูกค้าอยู่แล้ว']);
                    }
                    $customer->restore();
                } else {
                    $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'CUSTOMER')->where('is_active', true)->lockForUpdate()->firstOrFail();
                    $data['code'] ??= $sequences->issueAvailable($sequence, now(), fn (string $code) => Party::withTrashed()->where('code', $code)->exists());
                    $customer = Party::query()->create([...$this->partyValues($data), 'is_active' => true, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
                    $sequences->recordIssued($sequence, $data['code'], Party::class, $customer->id, now(), $request->user()->id);
                }

                $customer->roles()->create([...$this->roleValues($data), 'role' => 'CUSTOMER']);
                $this->syncPartyActive($customer);
                $customer->update(['updated_by' => $request->user()->id]);
                $this->syncCustomerFoundation($customer, $data);
                $audit->record('pos.customer.created', $customer, $before, $this->auditValues($customer->fresh('customerRole')), $request->user(), $request);

                return [$customer, $attached];
            });
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            throw ValidationException::withMessages(['code' => 'รหัสหรือข้อมูลภาษีถูกบันทึกโดยผู้ใช้อื่นแล้ว กรุณาตรวจสอบอีกครั้ง']);
        }

        return response()->json([
            'status' => true,
            'msg' => $attached ? 'เพิ่มบทบาทลูกค้าให้คู่ค้าเดิมแล้ว' : 'เพิ่มลูกค้าแล้ว',
            'redirect' => route('pos.customers.index'),
            'customer' => ['id' => $customer->id, 'text' => $customer->code.' · '.$customer->name],
        ]);
    }

    public function storeQuickAddress(Request $request, Party $customer, AuditLogger $audit): JsonResponse
    {
        $data = $request->validate([
            'address_type' => ['required', 'in:BILLING,SHIPPING'],
            'label' => ['nullable', 'string', 'max:100'],
            'recipient_name' => ['nullable', 'string', 'max:255'],
            'address_line' => ['required', 'string', 'max:2000'],
        ]);

        $address = DB::transaction(function () use ($request, $customer, $audit, $data) {
            $customer = Party::query()->lockForUpdate()->findOrFail($customer->id);
            $this->customerRoleOrFail($customer);
            $before = $this->auditValues($customer);
            $address = $customer->addresses()->create([
                ...$data,
                'address_line' => trim($data['address_line']),
                'is_default' => ! $customer->addresses()->where('address_type', $data['address_type'])->where('is_default', true)->exists(),
                'is_active' => true,
            ]);
            $audit->record('pos.customer.updated', $customer, $before, $this->auditValues($customer->fresh()), $request->user(), $request);

            return $address;
        });

        $value = collect([$address->address_line, $address->district, $address->amphoe, $address->province, $address->postal_code])->filter()->implode(' ');

        return response()->json(['status' => true, 'msg' => 'เพิ่มที่อยู่แล้ว', 'address' => [
            'id' => $address->id,
            'type' => $address->address_type,
            'value' => $value,
            'text' => collect([$address->label ?: 'ที่อยู่', $address->recipient_name, $address->address_line])->filter()->implode(' · '),
        ]]);
    }

    public function update(SaveCustomerRequest $request, Party $customer, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $customer, $audit) {
            $customer = Party::query()->lockForUpdate()->findOrFail($customer->id);
            $role = $this->customerRoleOrFail($customer);
            $before = $this->auditValues($customer->load('customerRole'));
            $data = $request->validated();

            if ($this->hasAnyFinancialHistory($customer) && ($customer->code !== $data['code'] || $customer->type !== $data['type'] || $customer->tax_id !== ($data['tax_id'] ?? null) || $customer->branch_code !== $data['branch_code'])) {
                throw ValidationException::withMessages(['code' => 'คู่ค้าที่มีประวัติทางการเงินแล้วไม่สามารถเปลี่ยนรหัสหรือข้อมูลภาษีได้']);
            }

            $customer->update([...$this->partyValues($data), 'updated_by' => $request->user()->id]);
            $role->update($this->roleValues($data));
            $this->syncPartyActive($customer);
            $this->syncCustomerFoundation($customer, $data);
            $audit->record('pos.customer.updated', $customer, $before, $this->auditValues($customer->fresh('customerRole')), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขลูกค้าแล้ว']);
    }

    public function destroy(Request $request, Party $customer, AuditLogger $audit): JsonResponse
    {
        $role = $this->customerRoleOrFail($customer);

        DB::transaction(function () use ($request, $customer, $role, $audit) {
            $customer = Party::query()->lockForUpdate()->findOrFail($customer->id);
            $role = PartyRole::query()->lockForUpdate()->findOrFail($role->id);
            if ($this->hasCustomerFinancialHistory($customer)) {
                throw ValidationException::withMessages(['customer' => 'ไม่สามารถลบบทบาทลูกค้าที่มีประวัติลูกหนี้หรือเอกสารรับเงินได้ กรุณาปิดใช้งานแทน']);
            }
            $before = $this->auditValues($customer->load('customerRole'));
            $role->delete();
            $customer->update(['updated_by' => $request->user()->id]);
            $customer->roles()->doesntExist() ? $customer->delete() : $this->syncPartyActive($customer);
            $audit->record('pos.customer.deleted', $customer, $before, ['customer_role' => null], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบบทบาทลูกค้าแล้ว']);
    }

    private function formView(Party $customer, PartyRole $customerRole): View
    {
        $paymentTerms = PaymentTerm::query()->where(fn (Builder $query) => $query->where('is_active', true)
            ->when($customerRole->payment_term_id, fn (Builder $query, int $id) => $query->orWhere('id', $id)))
            ->orderBy('credit_days')->orderBy('code')->get(['id', 'code', 'name', 'credit_days']);

        $customer->load(['customerGroups', 'addresses']);
        $customerGroups = CustomerGroup::query()->forCompany($this->companySettingId())->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']);

        return view('Pos::customers.form', compact('customer', 'customerRole', 'paymentTerms', 'customerGroups'));
    }

    private function customersQuery(): Builder
    {
        return Party::query()->join('party_roles as customer_roles', function ($join) {
            $join->on('customer_roles.party_id', '=', 'parties.id')->where('customer_roles.role', 'CUSTOMER');
        })->leftJoin('finance_payment_terms as payment_terms', 'payment_terms.id', '=', 'customer_roles.payment_term_id')
            ->select(['parties.id', 'parties.code', 'parties.name', 'parties.type', 'parties.tax_id', 'parties.branch_code', 'parties.contact_name', 'parties.phone', 'parties.email',
                'customer_roles.credit_limit', 'customer_roles.is_active', 'payment_terms.code as payment_term_code', 'payment_terms.name as payment_term_name'])
            ->selectSub(function ($query) {
                $query->from('pos_customer_groups')
                    ->join('pos_customer_group_party', 'pos_customer_group_party.customer_group_id', '=', 'pos_customer_groups.id')
                    ->whereColumn('pos_customer_group_party.party_id', 'parties.id')
                    ->where('pos_customer_groups.company_setting_id', $this->companySettingId())
                    ->whereNull('pos_customer_groups.deleted_at')
                    ->limit(1)
                    ->select('pos_customer_groups.name');
            }, 'customer_group_name');
    }

    private function applySearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->where('parties.code', 'like', "%{$search}%")->orWhere('parties.name', 'like', "%{$search}%")
                ->orWhere('parties.tax_id', 'like', "%{$search}%")->orWhere('parties.contact_name', 'like', "%{$search}%")
                ->orWhere('parties.phone', 'like', "%{$search}%")->orWhere('parties.email', 'like', "%{$search}%")
                ->orWhere('payment_terms.code', 'like', "%{$search}%")->orWhere('payment_terms.name', 'like', "%{$search}%"));
        }
    }

    private function applyOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 'parties.code', 1 => 'parties.name', 2 => 'parties.type', 3 => 'parties.tax_id', 4 => 'parties.contact_name',
            5 => 'payment_terms.code', 6 => 'customer_roles.credit_limit', 7 => 'customer_roles.is_active'];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'parties.code';
        $query->reorder($column, $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc')->orderBy('parties.id');
    }

    private function matchingParty(array $data): ?Party
    {
        $byCode = Party::withTrashed()->where('code', $data['code'])->lockForUpdate()->first();
        $byTax = ! empty($data['tax_id']) ? Party::withTrashed()->where('tax_id', $data['tax_id'])->where('branch_code', $data['branch_code'])->lockForUpdate()->first() : null;
        if ($byCode && $byTax && ! $byCode->is($byTax)) {
            throw ValidationException::withMessages(['code' => 'รหัสและเลขผู้เสียภาษีตรงกับคนละคู่ค้า', 'tax_id' => 'เลขผู้เสียภาษีและรหัสสาขาถูกใช้กับคู่ค้ารายอื่น']);
        }

        return $byCode ?? $byTax;
    }

    private function customerRoleOrFail(Party $customer): PartyRole
    {
        $role = $customer->customerRole()->first();
        abort_unless($role, 404);

        return $role;
    }

    private function syncPartyActive(Party $customer): void
    {
        $customer->update(['is_active' => $customer->roles()->where('is_active', true)->exists()]);
    }

    private function hasCustomerFinancialHistory(Party $customer): bool
    {
        $identities = [(string) $customer->id, $customer->code];

        return OpenItem::query()->where('party_type', 'CUSTOMER')->whereIn('party_id', $identities)->exists()
            || Settlement::query()->withTrashed()->where('party_type', 'CUSTOMER')->whereIn('party_id', $identities)->exists()
            || SalesDocument::query()->where('party_id', $customer->id)->where('status', '!=', 'VOID')->exists();
    }

    private function hasAnyFinancialHistory(Party $customer): bool
    {
        $identities = [(string) $customer->id, $customer->code];

        return OpenItem::query()->whereIn('party_id', $identities)->exists()
            || Settlement::query()->withTrashed()->whereIn('party_id', $identities)->exists()
            || SalesDocument::query()->where('party_id', $customer->id)->where('status', '!=', 'VOID')->exists()
            || PurchaseDocument::query()->where('supplier_id', $customer->id)->where('status', '!=', 'VOID')->exists();
    }

    private function partyValues(array $data): array
    {
        return collect($data)->only(['code', 'name', 'type', 'tax_id', 'branch_code', 'contact_name', 'phone', 'email', 'address'])->all();
    }

    private function roleValues(array $data): array
    {
        return collect($data)->only(['payment_term_id', 'credit_limit', 'is_active'])->all();
    }

    private function syncCustomerFoundation(Party $customer, array $data): void
    {
        $groupId = $data['customer_group_id'] ?? null;
        $customer->customerGroups()->sync($groupId ? [(int) $groupId] : []);

        $addresses = collect($data['addresses'] ?? [])
            ->filter(fn (array $address) => trim((string) ($address['address_line'] ?? '')) !== '')
            ->values();
        $existing = $customer->addresses()->whereIn('address_type', ['BILLING', 'SHIPPING'])->lockForUpdate()->get()->keyBy('id');
        $submittedIds = $addresses->pluck('id')->filter()->map(fn ($id) => (int) $id);

        if ($submittedIds->count() !== $submittedIds->unique()->count() || $submittedIds->diff($existing->keys())->isNotEmpty()) {
            throw ValidationException::withMessages(['addresses' => 'พบที่อยู่ที่ไม่ใช่ของลูกค้ารายนี้ กรุณาโหลดหน้าใหม่แล้วลองอีกครั้ง']);
        }

        $defaultSet = [];
        foreach ($addresses as $payload) {
            $type = $payload['address_type'];
            $values = [
                'label' => $payload['label'] ?? null,
                'recipient_name' => $payload['recipient_name'] ?? null,
                'address_line' => trim((string) $payload['address_line']),
                'district' => $payload['district'] ?? null,
                'amphoe' => $payload['amphoe'] ?? null,
                'province' => $payload['province'] ?? null,
                'postal_code' => $payload['postal_code'] ?? null,
                'phone' => $payload['phone'] ?? null,
                'is_default' => ! isset($defaultSet[$type]),
                'is_active' => true,
            ];
            $defaultSet[$type] = true;

            if ($id = $payload['id'] ?? null) {
                $existing[(int) $id]->update($values);
            } else {
                $customer->addresses()->create(['address_type' => $type, ...$values]);
            }
        }

        $customer->addresses()->whereIn('id', $existing->keys()->diff($submittedIds))->delete();
    }

    private function auditValues(Party $customer): array
    {
        $customer->loadMissing(['customerRole', 'customerGroups', 'addresses']);

        return [...$customer->only(['code', 'name', 'type', 'tax_id', 'branch_code', 'contact_name', 'phone', 'email', 'address']),
            'customer_role' => $customer->customerRole?->only(['payment_term_id', 'credit_limit', 'is_active']),
            'customer_group' => $customer->customerGroups->first()?->only(['id', 'code', 'name']),
            'addresses' => $customer->addresses->map(fn (PartyAddress $address) => $address->only(['address_type', 'label', 'recipient_name', 'address_line', 'district', 'amphoe', 'province', 'postal_code', 'phone']))->values()->all()];
    }

    private function companySettingId(): int
    {
        return (int) (CompanySetting::query()->value('id') ?: 1);
    }
}
