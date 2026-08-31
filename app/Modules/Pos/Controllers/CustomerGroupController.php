<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\CustomerGroup;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Requests\SaveCustomerGroupRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class CustomerGroupController extends Controller
{
    public function index(): View
    {
        return view('Pos::customer-groups.index');
    }

    public function data(Request $request): JsonResponse
    {
        $table = DataTables::eloquent($this->groupsQuery())
            ->filter(fn (Builder $query) => $this->applySearch($query, $request))
            ->order(fn (Builder $query) => $this->applyOrder($query, $request))
            ->addColumn('party_count', fn (CustomerGroup $group) => (int) $group->party_count);

        if ($request->user()->hasPermission('pos.customer-groups.update')) {
            $table->addColumn('edit_url', fn (CustomerGroup $group) => route('pos.customer-groups.edit', $group));
        }
        if ($request->user()->hasPermission('pos.customer-groups.delete')) {
            $table->addColumn('delete_url', fn (CustomerGroup $group) => route('pos.customer-groups.destroy', $group));
        }

        return $table->toJson();
    }

    public function create(): View
    {
        return view('Pos::customer-groups.form', ['customerGroup' => new CustomerGroup(['is_active' => true])]);
    }

    public function edit(CustomerGroup $customerGroup): View
    {
        $customerGroup = $this->scopedGroup($customerGroup);
        return view('Pos::customer-groups.form', compact('customerGroup'));
    }

    public function store(SaveCustomerGroupRequest $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        try {
            $group = DB::transaction(fn () => CustomerGroup::create([
                'company_setting_id' => $this->companySettingId(),
                ...$request->validated(),
                'created_by' => $request->user()->id,
                'updated_by' => $request->user()->id,
            ]));
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) {
                throw $exception;
            }
            throw ValidationException::withMessages(['code' => 'รหัสกลุ่มนี้มีอยู่แล้วในบริษัทปัจจุบัน']);
        }

        $audit->record('pos.customer_group.created', $group, [], $this->auditValues($group), $request->user(), $request);

        return $request->expectsJson()
            ? response()->json(['status' => true, 'msg' => 'เพิ่มกลุ่มลูกค้าแล้ว', 'redirect' => route('pos.customer-groups.index')])
            : redirect()->route('pos.customer-groups.index');
    }

    public function update(SaveCustomerGroupRequest $request, CustomerGroup $customerGroup, AuditLogger $audit): JsonResponse
    {
        $customerGroup = $this->scopedGroup($customerGroup);
        $before = $this->auditValues($customerGroup);
        $customerGroup->update([...$request->validated(), 'updated_by' => $request->user()->id]);
        $audit->record('pos.customer_group.updated', $customerGroup, $before, $this->auditValues($customerGroup->fresh()), $request->user(), $request);

        return response()->json(['status' => true, 'msg' => 'แก้ไขกลุ่มลูกค้าแล้ว']);
    }

    public function destroy(Request $request, CustomerGroup $customerGroup, AuditLogger $audit): JsonResponse
    {
        $customerGroup = $this->scopedGroup($customerGroup);

        DB::transaction(function () use ($request, $audit, $customerGroup): void {
            $group = CustomerGroup::query()->forCompany($this->companySettingId())->lockForUpdate()->findOrFail($customerGroup->id);
            if ($group->parties()->exists()) {
                throw ValidationException::withMessages(['customer_group' => 'กลุ่มนี้มีลูกค้าใช้งานอยู่ กรุณาปิดใช้งานแทนการลบ']);
            }
            $before = $this->auditValues($group);
            $group->delete();
            $audit->record('pos.customer_group.deleted', $group, $before, ['deleted_at' => $group->deleted_at], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบกลุ่มลูกค้าแล้ว']);
    }

    private function groupsQuery(): Builder
    {
        return CustomerGroup::query()->forCompany($this->companySettingId())
            ->select(['pos_customer_groups.id', 'pos_customer_groups.code', 'pos_customer_groups.name', 'pos_customer_groups.is_active'])
            ->selectSub(fn ($query) => $query->from('pos_customer_group_party')->whereColumn('customer_group_id', 'pos_customer_groups.id')->selectRaw('count(*)'), 'party_count');
    }

    private function scopedGroup(CustomerGroup $group): CustomerGroup
    {
        return CustomerGroup::query()->forCompany($this->companySettingId())->findOrFail($group->id);
    }

    private function companySettingId(): int
    {
        return (int) (CompanySetting::query()->value('id') ?: 1);
    }

    private function applySearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $query) => $query->where('pos_customer_groups.code', 'like', "%{$search}%")
                ->orWhere('pos_customer_groups.name', 'like', "%{$search}%"));
        }
    }

    private function applyOrder(Builder $query, Request $request): void
    {
        $columns = [0 => 'pos_customer_groups.code', 1 => 'pos_customer_groups.name', 2 => 'pos_customer_groups.is_active', 3 => 'party_count'];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? $columns[0];
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';
        $query->reorder($column, $direction)->orderBy('pos_customer_groups.id');
    }

    private function auditValues(CustomerGroup $group): array
    {
        return $group->only(['company_setting_id', 'code', 'name', 'is_active', 'deleted_at']);
    }
}
