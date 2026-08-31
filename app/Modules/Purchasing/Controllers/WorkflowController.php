<?php

namespace App\Modules\Purchasing\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Services\ModuleCapability;
use App\Modules\Platform\Services\WorkflowCatalog;
use App\Modules\Platform\Services\WorkflowRuntimeResolver;
use App\Modules\Platform\Services\WorkflowStepPresenter;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Module-local workflow center for the Purchasing program. */
class WorkflowController extends Controller
{
    public function index(Request $request, ModuleCapability $capability, WorkflowRuntimeResolver $runtime): View
    {
        $warehouse = $request->attributes->get('selectedWarehouse');

        return view('Purchasing::workflow.index', [
            'program' => $request->attributes->get('selectedProgram'),
            'warehouse' => $warehouse,
            'workflows' => $this->prepare($capability, $runtime, (int) $warehouse->id),
        ]);
    }

    private function prepare(ModuleCapability $capability, WorkflowRuntimeResolver $runtime, int $warehouseId): array
    {
        $workflows = $runtime->decorate('wms', WorkflowCatalog::for('wms', $capability), auth()->user(), $warehouseId);
        $workflows = array_values(array_filter($workflows, fn (array $workflow): bool => ($workflow['code'] ?? null) === 'procure-to-pay'));

        return array_map(function (array $workflow): array {
            $workflow['steps'] = array_map(
                fn (array $step): array => WorkflowStepPresenter::present($step, auth()->user()),
                $workflow['steps']
            );

            return $workflow;
        }, $workflows);
    }
}
