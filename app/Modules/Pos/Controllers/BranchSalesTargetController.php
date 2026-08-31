<?php

namespace App\Modules\Pos\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Pos\Models\BranchSalesTarget;
use App\Modules\Wms\Support\WmsDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class BranchSalesTargetController extends Controller
{
    public function index(): View
    {
        return view('Pos::branch-sales-targets.index');
    }

    public function data(Request $request): JsonResponse
    {
        $branchId = $this->branchId($request);

        return DataTables::eloquent(BranchSalesTarget::query()->where('branch_id', $branchId))
            ->order(fn (Builder $query) => $query->orderByDesc('period_start')->orderByDesc('id'))
            ->editColumn('period_start', fn (BranchSalesTarget $target) => $target->period_start?->format('d/m/Y'))
            ->editColumn('period_end', fn (BranchSalesTarget $target) => $target->period_end?->format('d/m/Y'))
            ->addColumn('edit_url', fn (BranchSalesTarget $target) => $request->user()->hasPermission('pos.branch-sales-targets.update') ? route('pos.branch-sales-targets.edit', $target) : null)
            ->addColumn('delete_url', fn (BranchSalesTarget $target) => $request->user()->hasPermission('pos.branch-sales-targets.delete') ? route('pos.branch-sales-targets.destroy', $target) : null)
            ->toJson();
    }

    public function create(Request $request): View
    {
        return view('Pos::branch-sales-targets.form', ['target' => new BranchSalesTarget(['period_start' => now()->startOfMonth(), 'period_end' => now()->endOfMonth()]), 'branch' => $request->attributes->get('selectedBranch')]);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        $data = $this->validated($request);
        $branchId = $this->branchId($request);
        $target = DB::transaction(function () use ($request, $audit, $data, $branchId): BranchSalesTarget {
            $target = BranchSalesTarget::query()->create([...$data, 'branch_id' => $branchId, 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id]);
            $audit->record('pos.branch_sales_target.created', $target, [], $target->toArray(), $request->user(), $request);

            return $target;
        });

        return response()->json(['status' => true, 'msg' => 'บันทึกเป้ายอดขายสาขาแล้ว', 'redirect' => route('pos.branch-sales-targets.index')]);
    }

    public function edit(Request $request, BranchSalesTarget $branchSalesTarget): View
    {
        $this->assertBranch($request, $branchSalesTarget);

        return view('Pos::branch-sales-targets.form', ['target' => $branchSalesTarget, 'branch' => $request->attributes->get('selectedBranch')]);
    }

    public function update(Request $request, BranchSalesTarget $branchSalesTarget, AuditLogger $audit): JsonResponse
    {
        $this->assertBranch($request, $branchSalesTarget);
        $data = $this->validated($request, $branchSalesTarget);
        DB::transaction(function () use ($request, $audit, $data, $branchSalesTarget): void {
            $target = BranchSalesTarget::query()->lockForUpdate()->findOrFail($branchSalesTarget->id);
            $before = $target->toArray();
            $target->update([...$data, 'updated_by' => $request->user()->id]);
            $audit->record('pos.branch_sales_target.updated', $target, $before, $target->fresh()->toArray(), $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'แก้ไขเป้ายอดขายสาขาแล้ว']);
    }

    public function destroy(Request $request, BranchSalesTarget $branchSalesTarget, AuditLogger $audit): JsonResponse
    {
        $this->assertBranch($request, $branchSalesTarget);
        DB::transaction(function () use ($request, $audit, $branchSalesTarget): void {
            $target = BranchSalesTarget::query()->lockForUpdate()->findOrFail($branchSalesTarget->id);
            $before = $target->toArray();
            $target->delete();
            $audit->record('pos.branch_sales_target.deleted', $target, $before, ['deleted_at' => now()->toIso8601String()], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบเป้ายอดขายสาขาแล้ว']);
    }

    private function validated(Request $request, ?BranchSalesTarget $target = null): array
    {
        $data = $request->validate([
            'period_start' => ['required', 'date_format:Y-m-d'],
            'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
            'target_sales_amount' => ['nullable', ...WmsDecimal::rule(), 'min:0'],
            'target_gross_profit_amount' => ['nullable', ...WmsDecimal::rule()],
        ]);

        $duplicate = BranchSalesTarget::query()
            ->where('branch_id', $this->branchId($request))
            ->whereDate('period_start', '<=', $data['period_end'])
            ->whereDate('period_end', '>=', $data['period_start'])
            ->when($target, fn (Builder $query) => $query->where('id', '<>', $target->id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages(['period_start' => 'ช่วงเวลานี้ทับซ้อนกับเป้าหมายของสาขาที่มีอยู่แล้ว']);
        }

        return $data;
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }

    private function assertBranch(Request $request, BranchSalesTarget $target): void
    {
        abort_unless($target->branch_id === $this->branchId($request), 404);
    }
}
