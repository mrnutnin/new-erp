<?php

namespace App\Modules\Settings\Controllers;

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
        return view('Settings::workflow.index', [
            'program' => $request->attributes->get('selectedProgram'),
            'workflows' => $this->prepare('settings', $capability, $runtime),
        ]);
    }

    private function prepare(string $program, ModuleCapability $capability, WorkflowRuntimeResolver $runtime): array
    {
        return array_map(function (array $workflow): array {
            $workflow['steps'] = array_map(function (array $step): array {
                return WorkflowStepPresenter::present($step, auth()->user());
            }, $workflow['steps']);

            return $workflow;
        }, $runtime->decorate($program, WorkflowCatalog::for($program, $capability), auth()->user(), null));
    }
}
