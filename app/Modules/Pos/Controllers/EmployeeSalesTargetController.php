<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\EmployeeSalesTarget;
use App\Modules\Pos\Requests\SaveEmployeeSalesTargetRequest;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class EmployeeSalesTargetController extends Controller
{
    public function index(): View
    {
        return view('Pos::employee-sales-targets.index');
    }

    public function data(Request $request): JsonResponse
    {
        $targets = EmployeeSalesTarget::query()->with('employee:id,name,username')
            ->where('branch_id', $this->branchId($request));

        return DataTables::eloquent($targets)
            ->filter(function (Builder $query) use ($request): void {
                $search = trim((string) $request->input('search.value', ''));
                if ($search !== '') {
                    $query->whereHas('employee', fn (Builder $employee) => $employee->where('name', 'like', "%{$search}%")->orWhere('username', 'like', "%{$search}%"));
                }
            })
            ->order(function (Builder $query) use ($request): void {
                $columns = [0 => 'period_start', 1 => 'period_end', 3 => 'sales_target', 4 => 'gross_profit_target'];
                $query->reorder($columns[(int) $request->input('order.0.column', 0)] ?? 'period_start', $request->input('order.0.dir') === 'asc' ? 'asc' : 'desc')->orderByDesc('id');
            })
            ->addColumn('employee_label', fn (EmployeeSalesTarget $target) => $target->employee?->name.($target->employee?->username ? ' · '.$target->employee->username : ''))
            ->editColumn('period_start', fn (EmployeeSalesTarget $target) => $target->period_start->format('d/m/Y'))
            ->editColumn('period_end', fn (EmployeeSalesTarget $target) => $target->period_end->format('d/m/Y'))
            ->addColumn('edit_url', fn (EmployeeSalesTarget $target) => $request->user()->hasPermission('pos.employee-sales-targets.update') ? route('pos.employee-sales-targets.edit', $target) : null)
            ->addColumn('delete_url', fn (EmployeeSalesTarget $target) => $request->user()->hasPermission('pos.employee-sales-targets.delete') ? route('pos.employee-sales-targets.destroy', $target) : null)
            ->toJson();
    }

    public function create(Request $request): View
    {
        return $this->form(new EmployeeSalesTarget(['period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth(), 'sales_target' => '0.00', 'gross_profit_target' => '0.00']), $request);
    }

    public function edit(Request $request, EmployeeSalesTarget $employeeSalesTarget): View
    {
        return $this->form($this->scopedTarget($request, $employeeSalesTarget), $request);
    }

    public function store(SaveEmployeeSalesTargetRequest $request, AuditLogger $audit): JsonResponse
    {
        $target = DB::transaction(function () use ($request, $audit): EmployeeSalesTarget {
            $data = $request->validated();
            $branchId = $this->branchId($request);
            $this->employeeForBranch((int) $data['user_id'], $branchId);
            $this->assertPeriodAvailable($branchId, (int) $data['user_id'], $data['period_start'], $data['period_end']);
            $target = EmployeeSalesTarget::query()->create([...$data, 'branch_id' => $branchId, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $audit->record('pos.employee-sales-target.created', $target, [], $target->toArray(), $request->user(), $request);

            return $target;
        });

        return response()->json(['status' => true, 'msg' => 'เพิ่มเป้าหมายพนักงานแล้ว', 'redirect' => route('pos.employee-sales-targets.edit', $target)]);
    }

    public function update(SaveEmployeeSalesTargetRequest $request, EmployeeSalesTarget $employeeSalesTarget, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $employeeSalesTarget, $audit): void {
            $target = $this->scopedTarget($request, $employeeSalesTarget, true);
            $data = $request->validated();
            $branchId = $this->branchId($request);
            $this->employeeForBranch((int) $data['user_id'], $branchId);
            $this->assertPeriodAvailable($branchId, (int) $data['user_id'], $data['period_start'], $data['period_end'], $target->id);
            $before = $target->toArray();
            $target->update([...$data, 'updated_by' => $request->user()->id]);
            $audit->record('pos.employee-sales-target.updated', $target, $before, $target->fresh()->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขเป้าหมายพนักงานแล้ว']);
    }

    public function destroy(Request $request, EmployeeSalesTarget $employeeSalesTarget, AuditLogger $audit): JsonResponse
    {
        DB::transaction(function () use ($request, $employeeSalesTarget, $audit): void {
            $target = $this->scopedTarget($request, $employeeSalesTarget, true);
            $before = $target->toArray();
            $target->delete();
            $audit->record('pos.employee-sales-target.deleted', $target, $before, ['deleted_at' => now()->toIso8601String()], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบเป้าหมายพนักงานแล้ว']);
    }

    private function form(EmployeeSalesTarget $target, Request $request): View
    {
        return view('Pos::employee-sales-targets.form', ['target' => $target, 'employees' => $this->employeesForBranch($this->branchId($request))]);
    }

    private function employeesForBranch(int $branchId): Collection
    {
        return User::query()->where('is_active', true)->whereHas('warehouses', fn (Builder $warehouses) => $warehouses->where('branch_id', $branchId)->where('is_active', true))->orderBy('name')->get(['id', 'name', 'username']);
    }

    private function employeeForBranch(int $userId, int $branchId): void
    {
        if (! $this->employeesForBranch($branchId)->contains('id', $userId)) {
            throw ValidationException::withMessages(['user_id' => 'พนักงานต้องเปิดใช้งานและมีสิทธิ์ในสาขาปัจจุบัน']);
        }
    }

    private function assertPeriodAvailable(int $branchId, int $userId, string $start, string $end, ?int $ignoreId = null): void
    {
        $overlap = EmployeeSalesTarget::query()->where('branch_id', $branchId)->where('user_id', $userId)
            ->where('period_start', '<=', $end)->where('period_end', '>=', $start)->when($ignoreId, fn (Builder $query) => $query->whereKeyNot($ignoreId))->lockForUpdate()->exists();
        if ($overlap) {
            throw ValidationException::withMessages(['period_start' => 'พนักงานมีเป้าหมายที่ทับซ้อนกันในช่วงเวลานี้แล้ว']);
        }
    }

    private function scopedTarget(Request $request, EmployeeSalesTarget $target, bool $lock = false): EmployeeSalesTarget
    {
        $query = EmployeeSalesTarget::query()->whereKey($target->id)->where('branch_id', $this->branchId($request));
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
