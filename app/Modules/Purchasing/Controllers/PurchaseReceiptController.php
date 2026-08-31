<?php

namespace App\Modules\Purchasing\Controllers;

use Illuminate\Http\Request;

/** Transitional adapter; keeps GR business rules shared while exposing Purchasing URLs/views. */
class PurchaseReceiptController extends \App\Modules\Wms\Controllers\PurchaseReceiptController
{
    protected function moduleRoutePrefix(): string
    {
        return 'purchasing';
    }

    protected function moduleViewPrefix(): string
    {
        return 'Purchasing';
    }

    /** @return list<int> */
    protected function authorizedWarehouseIds(Request $request): array
    {
        return $request->user()->warehouses()
            ->where('is_active', true)
            ->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)
            ->pluck('warehouses.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
