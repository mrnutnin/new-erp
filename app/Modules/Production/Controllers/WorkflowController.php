<?php

namespace App\Modules\Production\Controllers;

use App\Modules\Platform\Services\ModuleCapability;
use App\Modules\Platform\Services\WorkflowCatalog;
use App\Modules\Platform\Services\WorkflowRuntimeResolver;
use App\Modules\Platform\Services\WorkflowStepPresenter;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class WorkflowController
{
    public function index(Request $request, ModuleCapability $capability, WorkflowRuntimeResolver $runtime): View
    {
        $user = $request->user();
        $workflows = $runtime->decorate(
            'production',
            WorkflowCatalog::for('production', $capability),
            $user,
            (int) $request->attributes->get('selectedWarehouse')->id
        );
        $workflows = array_map(function (array $workflow) use ($user): array {
            $workflow['steps'] = array_map(fn (array $step): array => WorkflowStepPresenter::present($step, $user), $workflow['steps']);

            return $workflow;
        }, $workflows);

        return view('Production::workflow.index', compact('workflows'));
    }
}
