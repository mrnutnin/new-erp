<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EntryController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('Settings::dashboard', [
            'program' => $request->attributes->get('selectedProgram'),
        ]);
    }
}
