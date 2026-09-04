<?php

namespace App\Modules\Platform\Services;

use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class BranchContext
{
    public function resolve(Request $request): ?Branch
    {
        $branchId = $request->session()->get('selected_branch_id');
        if ($branchId === null && ($warehouseId = $request->session()->get('selected_warehouse_id'))) {
            $branchId = Warehouse::query()->whereKey($warehouseId)->value('branch_id');
            if ($branchId !== null) {
                $request->session()->put('selected_branch_id', $branchId);
            }
        }
        if ($branchId === null) {
            return null;
        }

        $branch = Branch::query()->whereKey($branchId)->where('is_active', true)
            ->when($request->user()->branches()->exists(),
                fn ($query) => $query->whereIn('id', $request->user()->branches()->select('branches.id')),
                fn ($query) => $query->whereIn('id', $request->user()->warehouses()->where('warehouses.is_active', true)->select('warehouses.branch_id')))
            ->first();
        if ($branch === null) {
            $request->session()->forget(['selected_branch_id', 'selected_warehouse_id']);
        }

        return $branch;
    }

    public function defaultWarehouse(Request $request, Branch $branch): ?Warehouse
    {
        return $request->user()->warehouses()->with('branch')->where('warehouses.branch_id', $branch->id)
            ->where('warehouses.is_active', true)->orderBy('warehouses.name')->first();
    }
}
