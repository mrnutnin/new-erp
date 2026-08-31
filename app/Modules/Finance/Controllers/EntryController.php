<?php

namespace App\Modules\Finance\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EntryController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('Finance::dashboard', ['program' => $request->attributes->get('selectedProgram'), 'warehouse' => $request->attributes->get('selectedWarehouse')]);
    }
}
