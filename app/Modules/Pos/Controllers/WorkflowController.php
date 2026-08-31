<?php

namespace App\Modules\Pos\Controllers;

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
            $workflow['steps'] = array_map(function (array $step): array {
                return WorkflowStepPresenter::present($step, auth()->user());
            }, $workflow['steps']);

            return $workflow;
        }, $runtime->decorate('pos', WorkflowCatalog::for('pos', $capability), auth()->user(), (int) $request->attributes->get('selectedWarehouse')->id));

        return view('Pos::workflow.index', [
            'program' => $request->attributes->get('selectedProgram'),
            'warehouse' => $request->attributes->get('selectedWarehouse'),
            'workflows' => $workflows,
        ]);
    }
}
