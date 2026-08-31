<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\SalesCommissionPlan;
use App\Modules\Pos\Requests\SaveSalesCommissionPlanRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class SalesCommissionPlanController extends Controller
{
    public function index(): View
    {
        return view('Pos::sales-commission-plans.index');
    }

    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'basis' => ['nullable', 'in:POSTED_SALE,COLLECTED_RECEIPT,GROSS_PROFIT'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $plans = $this->plansForBranch($request)
            ->withCount(['assignments as assignments_count' => fn (Builder $query) => $query->where('branch_id', $this->branchId($request)), 'commissionRecords'])
            ->when($filters['basis'] ?? null, fn (Builder $query, string $basis) => $query->where('basis', $basis))
            ->when(array_key_exists('is_active', $filters) && $filters['is_active'] !== null, fn (Builder $query) => $query->where('is_active', (bool) $filters['is_active']))
            ->select('pos_sales_commission_plans.*');

        return DataTables::eloquent($plans)
            ->filter(function (Builder $query) use ($request): void {
                $search = trim((string) $request->input('search.value', ''));
                if ($search !== '') {
                    $query->where(fn (Builder $q) => $q->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
                }
            })
            ->order(function (Builder $query) use ($request): void {
                $columns = [0 => 'code', 1 => 'name', 2 => 'basis', 3 => 'rate', 4 => 'effective_from', 5 => 'effective_to', 6 => 'assignments_count', 7 => 'is_active'];
                $query->reorder($columns[(int) $request->input('order.0.column', 0)] ?? 'code', $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc')->orderByDesc('id');
            })
            ->addColumn('basis_label', fn (SalesCommissionPlan $plan) => $this->basisLabel($plan->basis))
            ->editColumn('effective_from', fn (SalesCommissionPlan $plan) => $plan->effective_from?->format('d/m/Y') ?: '—')
            ->editColumn('effective_to', fn (SalesCommissionPlan $plan) => $plan->effective_to?->format('d/m/Y') ?: '—')
            ->addColumn('status_label', fn (SalesCommissionPlan $plan) => $plan->is_active ? 'ใช้งาน' : 'ปิดใช้งาน')
            ->addColumn('edit_url', fn (SalesCommissionPlan $plan) => $request->user()->hasPermission('pos.commission-plans.update') ? route('pos.sales-commission-plans.edit', $plan) : null)
            ->addColumn('delete_url', fn (SalesCommissionPlan $plan) => $plan->commission_records_count === 0 && $request->user()->hasPermission('pos.commission-plans.delete') ? route('pos.sales-commission-plans.destroy', $plan) : null)
            ->toJson();
    }

    public function create(Request $request): View
    {
        return $this->form(new SalesCommissionPlan(['basis' => 'POSTED_SALE', 'rate' => '0.0000', 'is_active' => true]), $request);
    }

    public function edit(Request $request, SalesCommissionPlan $salesCommissionPlan): View
    {
        $plan = $this->scopedPlan($request, $salesCommissionPlan);

        return $this->form($plan, $request);
    }

    public function store(SaveSalesCommissionPlanRequest $request, AuditLogger $audit): JsonResponse
    {
        $plan = DB::transaction(function () use ($request, $audit): SalesCommissionPlan {
            $plan = SalesCommissionPlan::query()->create($this->values($request->validated(), $request, true));
            $this->syncAssignments($plan, $request->validated('assignments'));
            $audit->record('pos.sales-commission-plan.created', $plan, [], $this->auditValues($plan), $request->user(), $request);

            return $plan;
        });

        return response()->json(['status' => true, 'msg' => 'เพิ่มแผนคอมมิชชั่นแล้ว', 'redirect' => route('pos.sales-commission-plans.index')]);
    }

    public function update(SaveSalesCommissionPlanRequest $request, SalesCommissionPlan $salesCommissionPlan, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $salesCommissionPlan, $audit): void {
            $plan = $this->scopedPlan($request, $salesCommissionPlan, true);
            $before = $this->auditValues($plan);
            $plan->update($this->values($request->validated(), $request));
            $this->syncAssignments($plan, $request->validated('assignments'));
            $audit->record('pos.sales-commission-plan.updated', $plan, $before, $this->auditValues($plan), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขแผนคอมมิชชั่นแล้ว']);
    }

    public function destroy(Request $request, SalesCommissionPlan $salesCommissionPlan, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $salesCommissionPlan, $audit): void {
            $plan = $this->scopedPlan($request, $salesCommissionPlan, true);
            if ($plan->commissionRecords()->exists()) {
                throw ValidationException::withMessages(['plan' => 'แผนนี้ถูกนำไปคำนวณคอมมิชชั่นแล้ว ไม่สามารถลบได้ กรุณาปิดใช้งานแทน']);
            }
            $before = $this->auditValues($plan);
            $plan->delete();
            $audit->record('pos.sales-commission-plan.deleted', $plan, $before, ['deleted_at' => now()->toIso8601String()], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบแผนคอมมิชชั่นแล้ว']);
    }

    private function form(SalesCommissionPlan $plan, Request $request): View
    {
        $branchId = $this->branchId($request);

        return view('Pos::sales-commission-plans.form', [
            'plan' => $plan,
            'assignments' => $plan->exists ? $plan->assignments()->where('branch_id', $branchId)->get() : collect(),
            'users' => User::query()->where('is_active', true)->whereHas('warehouses', fn (Builder $query) => $query->where('branch_id', $branchId)->where('is_active', true))->orderBy('name')->get(['id', 'name', 'username']),
            'branch' => Branch::query()->whereKey($branchId)->firstOrFail(['id', 'code', 'name']),
        ]);
    }

    private function values(array $data, Request $request, bool $creating = false): array
    {
        $values = collect($data)->only(['code', 'name', 'basis', 'rate', 'effective_from', 'effective_to', 'is_active'])->put('updated_by', $request->user()->id);
        if ($creating) {
            $values->put('created_by', $request->user()->id);
        }

        return $values->all();
    }

    private function syncAssignments(SalesCommissionPlan $plan, array $assignments): void
    {
        $plan->assignments()->where('branch_id', $assignments[0]['branch_id'])->delete();
        $plan->assignments()->createMany(collect($assignments)->map(fn (array $assignment) => collect($assignment)->only(['user_id', 'branch_id'])->all())->all());
    }

    private function auditValues(SalesCommissionPlan $plan): array
    {
        return $plan->fresh()->load('assignments')->toArray();
    }

    private function basisLabel(string $basis): string
    {
        return ['POSTED_SALE' => 'ยอดขายที่ลงบัญชี', 'COLLECTED_RECEIPT' => 'ยอดรับชำระ', 'GROSS_PROFIT' => 'กำไรขั้นต้น'][$basis] ?? $basis;
    }

    private function plansForBranch(Request $request): Builder
    {
        return SalesCommissionPlan::query()->whereHas('assignments', fn (Builder $query) => $query->where('branch_id', $this->branchId($request)));
    }

    private function scopedPlan(Request $request, SalesCommissionPlan $plan, bool $lock = false): SalesCommissionPlan
    {
        $query = $this->plansForBranch($request)->whereKey($plan->id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->firstOrFail();
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }
}
