<?php

namespace App\Modules\Wms\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Wms\Services\StockMinMaxAlertService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EntryController extends Controller
{
    public function __invoke(Request $request, StockMinMaxAlertService $minMaxAlerts): View
    {
        $warehouse = $request->attributes->get('selectedWarehouse');

        return view('Wms::dashboard', [
            'program' => $request->attributes->get('selectedProgram'),
            'warehouse' => $warehouse,
            'minMaxAlerts' => $minMaxAlerts->alerts($warehouse),
        ]);
    }
}
