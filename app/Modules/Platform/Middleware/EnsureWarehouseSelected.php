<?php

namespace App\Modules\Platform\Middleware;

use App\Modules\Platform\Services\BranchContext;
use App\Modules\Platform\Services\WarehouseContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWarehouseSelected
{
    public function __construct(private readonly WarehouseContext $warehouseContext, private readonly BranchContext $branchContext) {}

    public function handle(Request $request, Closure $next): Response
    {
        $branch = $request->attributes->get('selectedBranch') ?? $this->branchContext->resolve($request);
        $warehouse = $request->attributes->get('selectedWarehouse') ?? $this->warehouseContext->resolve($request, $branch) ?? ($branch ? $this->branchContext->defaultWarehouse($request, $branch) : null);

        if ($request->isMethod('GET') && $request->is('wms/*') && $request->filled('warehouse_id')) {
            $warehouse = $request->user()->warehouses()
                ->with('branch')
                ->whereKey($request->integer('warehouse_id'))
                ->where('is_active', true)
                ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
                ->firstOrFail();
        }

        if ($warehouse === null) {
            $request->session()->forget(['selected_branch_id', 'selected_warehouse_id']);

            return redirect()->route('branches.index')->with('error', 'กรุณาเลือกสาขาที่มีสิทธิ์ใช้งาน');
        }

        $request->attributes->set('selectedBranch', $branch ?? $warehouse->branch);
        $request->session()->put('selected_branch_id', $warehouse->branch_id);
        $request->session()->put('selected_warehouse_id', $warehouse->id);
        $request->attributes->set('selectedWarehouse', $warehouse);

        return $next($request);
    }
}
