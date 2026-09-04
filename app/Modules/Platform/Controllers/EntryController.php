<?php

namespace App\Modules\Platform\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\ModuleCapability;
use App\Modules\Platform\Support\ContextSelection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EntryController extends Controller
{
    public function __invoke(Request $request, ContextSelection $selection, ModuleCapability $capability): RedirectResponse
    {
        if ($request->user() === null) {
            return redirect()->route('login');
        }

        $program = $request->user()->programs()
            ->whereKey($request->session()->get('selected_program_id'))
            ->where('is_enabled', true)
            ->first();
        if ($program && ! $capability->isProgramAvailable($program->code)) {
            $program = null;
        }

        return redirect()->route($selection->nextRoute(
            $program?->id,
            $program?->requires_branch ?? true,
            $program?->requires_warehouse ?? true,
            $request->session()->get('selected_branch_id'),
            $this->canonicalEntryRoute($program?->code, $program?->entry_route ?? 'dashboard'),
        ));
    }

    private function canonicalEntryRoute(?string $programCode, string $entryRoute): string
    {
        return match ($programCode) {
            'purchasing' => 'purchasing.index',
            'wms' => 'wms.index',
            default => $entryRoute,
        };
    }
}
