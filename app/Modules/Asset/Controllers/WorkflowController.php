<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\ModuleCapability;
use App\Modules\Platform\Services\WorkflowCatalog;
use App\Modules\Platform\Services\WorkflowRuntimeResolver;
use App\Modules\Platform\Services\WorkflowStepPresenter;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkflowController extends Controller
{
    public function index(Request $request, ModuleCapability $capability, WorkflowRuntimeResolver $runtime): View
    {
        $workflows = array_map(function (array $workflow): array {
            $workflow['steps'] = array_map(fn (array $step): array => WorkflowStepPresenter::present($step, auth()->user()), $workflow['steps']);

            return $workflow;
        }, $runtime->decorate('asset', WorkflowCatalog::for('asset', $capability), auth()->user(), null));

        return view('Asset::workflow.index', ['program' => $request->attributes->get('selectedProgram'), 'workflows' => $workflows]);
    }
}
