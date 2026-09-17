<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\ModuleCapability;
use App\Modules\Platform\Services\WorkflowCatalog;
use App\Modules\Platform\Services\WorkflowRuntimeResolver;
use App\Modules\Platform\Services\WorkflowStepPresenter;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkflowController extends Controller
{
    public function index(Request $request, ModuleCapability $capability, WorkflowRuntimeResolver $runtime): View
    {
        $workflows = array_map(function (array $workflow) use ($request): array {
            $workflow['steps'] = array_map(fn (array $step): array => WorkflowStepPresenter::present($step, $request->user()), $workflow['steps']);
            return $workflow;
        }, $runtime->decorate('crm', WorkflowCatalog::for('crm', $capability), $request->user(), null));

        return view('Crm::workflow.index', compact('workflows'));
    }
}
