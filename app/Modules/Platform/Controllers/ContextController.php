<?php

namespace App\Modules\Platform\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Platform\Requests\SelectBranchRequest;
use App\Modules\Platform\Requests\SelectProgramRequest;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\BranchContext;
use App\Modules\Platform\Services\ModuleCapability;
use App\Modules\Platform\Services\WarehouseContext;
use App\Modules\Platform\Support\ContextSelection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ContextController extends Controller
{
    public function programs(Request $request, ModuleCapability $capability): View
    {
        $programs = $request->user()->programs()
            ->where('is_enabled', true)
            ->orderBy('sort_order')
            ->get()
            ->filter(fn ($program) => $capability->isProgramAvailable($program->code))
            ->values();

        return view('Platform::context.select-program', compact('programs'));
    }

    public function storeProgram(
        SelectProgramRequest $request,
        ContextSelection $selection,
        BranchContext $branchContext,
        WarehouseContext $warehouseContext,
        ModuleCapability $capability,
    ): JsonResponse|RedirectResponse {
        $program = $request->user()->programs()
            ->whereKey($request->integer('program_id'))
            ->where('is_enabled', true)
            ->first();

        abort_if($program === null, 403, 'คุณไม่มีสิทธิ์ใช้งานโปรแกรมนี้');
        abort_unless($capability->isProgramAvailable($program->code), 403, 'โปรแกรมนี้ยังไม่ได้เปิดใช้งานสำหรับบริษัท');

        $request->session()->put('selected_program_id', $program->id);
        $branch = $branchContext->resolve($request);
        $warehouse = $branch ? $warehouseContext->resolve($request, $branch) ?? $branchContext->defaultWarehouse($request, $branch) : null;
        if ($warehouse) {
            $request->session()->put('selected_warehouse_id', $warehouse->id);
        }

        $redirect = route($selection->nextRoute(
            $program->id,
            $program->requires_branch,
            $program->requires_warehouse,
            $branch?->id,
            $this->canonicalEntryRoute($program->code, $program->entry_route),
        ));

        return $this->success($request, 'เลือกโปรแกรมแล้ว', $redirect);
    }

    public function branches(Request $request): View
    {
        $branches = Branch::query()->where('is_active', true)
            ->when($request->user()->branches()->exists(),
                fn ($query) => $query->whereIn('id', $request->user()->branches()->select('branches.id')),
                fn ($query) => $query->whereIn('id', $request->user()->warehouses()->where('warehouses.is_active', true)->select('warehouses.branch_id')))
            ->orderBy('name')
            ->get();

        return view('Platform::context.select-branch', [
            'branches' => $branches,
            'selectedBranch' => $request->attributes->get('selectedBranch'),
        ]);
    }

    public function storeBranch(SelectBranchRequest $request, ContextSelection $selection, BranchContext $branchContext, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $branch = Branch::query()->whereKey($request->integer('branch_id'))->where('is_active', true)
            ->when($request->user()->branches()->exists(),
                fn ($query) => $query->whereIn('id', $request->user()->branches()->select('branches.id')),
                fn ($query) => $query->whereIn('id', $request->user()->warehouses()->where('warehouses.is_active', true)->select('warehouses.branch_id')))
            ->first();

        abort_if($branch === null, 403, 'คุณไม่มีสิทธิ์ใช้งานสาขานี้');

        $before = ['branch_id' => $request->session()->get('selected_branch_id'), 'warehouse_id' => $request->session()->get('selected_warehouse_id')];
        $request->session()->put('selected_branch_id', $branch->id);
        $warehouse = $branchContext->defaultWarehouse($request, $branch);
        $request->session()->put('selected_warehouse_id', $warehouse?->id);
        if ($before['branch_id'] !== $branch->id || $before['warehouse_id'] !== $warehouse?->id) {
            $audit->record('platform.context.branch_selected', $branch, $before, ['branch_id' => $branch->id, 'warehouse_id' => $warehouse?->id], $request->user(), $request);
        }
        $program = $request->attributes->get('selectedProgram');
        $redirect = route($selection->nextRoute(
            $program->id,
            $program->requires_branch,
            $program->requires_warehouse,
            $branch->id,
            $this->canonicalEntryRoute($program->code, $program->entry_route),
        ));

        return $this->success($request, 'เลือกสาขาแล้ว', $redirect);
    }

    public function warehouses(): RedirectResponse
    {
        return redirect()->route('branches.index');
    }

    public function handoffToFinanceSettlement(Request $request, ModuleCapability $capability): RedirectResponse
    {
        abort_unless($request->user()->hasPermission('finance.settlements.create'), 403);
        $program = $request->user()->programs()->where('code', 'finance')->where('is_enabled', true)->first();
        abort_if($program === null || ! $capability->isProgramAvailable($program->code), 403, 'คุณไม่มีสิทธิ์ใช้งานโปรแกรม Finance');

        $request->session()->put('selected_program_id', $program->id);

        return redirect()->route('finance.settlements.create', ['open_item_id' => $request->integer('open_item_id')]);
    }

    private function success(Request $request, string $message, string $redirect): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(compact('message', 'redirect'));
        }

        return redirect()->to($redirect)->with('success', $message);
    }

    /**
     * Keep old database rows usable while program entry routes are migrated.
     * `purchasing` is Purchasing; `wms` is warehouse/inventory operations.
     */
    private function canonicalEntryRoute(string $programCode, string $entryRoute): string
    {
        return match ($programCode) {
            'purchasing' => 'purchasing.index',
            'wms' => 'wms.index',
            default => $entryRoute,
        };
    }
}
