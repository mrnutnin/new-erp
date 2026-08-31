<?php

namespace App\Modules\Purchasing\Controllers;

use Illuminate\Http\Request;

/** Transitional adapter; preserves the validated AP document contract during extraction. */
class PurchaseDocumentController extends \App\Modules\Wms\Controllers\PurchaseDocumentController
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
        return $request->user()->warehouses()->where('is_active', true)
            ->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)
            ->pluck('warehouses.id')->map(fn ($id): int => (int) $id)->all();
    }
}
