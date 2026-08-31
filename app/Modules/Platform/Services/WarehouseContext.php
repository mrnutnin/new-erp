<?php

namespace App\Modules\Platform\Services;

use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Http\Request;

class WarehouseContext
{
    public function resolve(Request $request, ?Branch $branch = null): ?Warehouse
    {
        $warehouseId = $request->session()->get('selected_warehouse_id');

        if ($warehouseId === null) {
            return null;
        }

        $warehouse = $request->user()->warehouses()
            ->with('branch')
            ->whereKey($warehouseId)
            ->where('is_active', true)
            ->when($branch, fn ($query) => $query->where('branch_id', $branch->id))
            ->first();

        if ($warehouse === null) {
            $request->session()->forget('selected_warehouse_id');
        }

        return $warehouse;
    }
}
