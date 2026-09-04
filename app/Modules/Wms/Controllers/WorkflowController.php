<?php

namespace App\Modules\Wms\Controllers;

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
        return view('Wms::workflow.index', [
            'program' => $request->attributes->get('selectedProgram'),
            'warehouse' => $request->attributes->get('selectedWarehouse'),
            'workflows' => $this->prepare($capability, $runtime, $request->attributes->get('selectedWarehouse')?->id),
        ]);
    }

    private function prepare(ModuleCapability $capability, WorkflowRuntimeResolver $runtime, ?int $warehouseId): array
    {
        $workflows = $runtime->decorate('wms', WorkflowCatalog::for('wms', $capability), auth()->user(), $warehouseId);
        // WorkflowCatalog splits mixed-mode workflows into `-setup` and `-daily`
        // variants, so match the base WMS workflow code instead of an exact code.
        $workflows = array_values(array_filter(
            $workflows,
            fn (array $workflow): bool => str_starts_with((string) ($workflow['code'] ?? ''), 'inventory-operations')
        ));

        return array_map(function (array $workflow): array {
            $workflow['steps'] = array_map(function (array $step): array {
                return WorkflowStepPresenter::present($step, auth()->user());
            }, $workflow['steps']);

            return $workflow;
        }, $workflows);
    }
}
