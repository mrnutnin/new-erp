<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Purchasing dashboard entry point.
 *
 * This is intentionally module-local. Inventory-only alerts belong to the
 * WMS dashboard and are not loaded while entering Purchasing.
 */
class EntryController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('Purchasing::dashboard', [
            'program' => $request->attributes->get('selectedProgram'),
            'warehouse' => $request->attributes->get('selectedWarehouse'),
            'minMaxAlerts' => collect(),
        ]);
    }
}
