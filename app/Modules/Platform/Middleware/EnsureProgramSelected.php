<?php

namespace App\Modules\Platform\Middleware;

use App\Modules\Platform\Services\BranchContext;
use App\Modules\Platform\Services\ModuleCapability;
use App\Modules\Platform\Services\WarehouseContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProgramSelected
{
    public function __construct(
        private readonly BranchContext $branchContext,
        private readonly WarehouseContext $warehouseContext,
        private readonly ModuleCapability $capability,
    ) {}

    public function handle(Request $request, Closure $next, ?string $requiredProgram = null): Response
    {
        $program = $request->user()->programs()
            ->whereKey($request->session()->get('selected_program_id'))
            ->where('is_enabled', true)
            ->first();
        if ($program && ! $this->capability->isProgramAvailable($program->code)) {
            $program = null;
        }

        if ($program === null) {
            $request->session()->forget('selected_program_id');

            return redirect()->route('programs.index')->with('error', 'กรุณาเลือกโปรแกรมที่มีสิทธิ์ใช้งาน');
        }

        if ($requiredProgram !== null && ! $this->matchesRequiredProgram((string) $program->code, $requiredProgram)) {
            return redirect()->route('programs.index')->with('error', 'กรุณาเลือกโปรแกรมที่ต้องการเข้าใช้งาน');
        }

        $request->attributes->set('selectedProgram', $program);

        if ($branch = $this->branchContext->resolve($request)) {
            $request->attributes->set('selectedBranch', $branch);
        }

        if ($warehouse = $this->warehouseContext->resolve($request, $branch ?? null) ?? ($branch ? $this->branchContext->defaultWarehouse($request, $branch) : null)) {
            $request->session()->put('selected_warehouse_id', $warehouse->id);
            $request->attributes->set('selectedWarehouse', $warehouse);
        }

        return $next($request);
    }

    private function matchesRequiredProgram(string $selectedCode, string $requiredProgram): bool
    {
        // WMS was split into Purchasing (internal code wms) and warehouse
        // operations (internal code inventory). Both currently share the Wms
        // route/provider surface while the domains are being separated.
        return $selectedCode === $requiredProgram
            || ($requiredProgram === 'wms' && $selectedCode === 'inventory');
    }
}
