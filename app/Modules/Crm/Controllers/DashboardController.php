<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Opportunity;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $openStages = ['NEW', 'CONTACTED', 'QUALIFIED', 'PROPOSAL', 'NEGOTIATION'];
        $query = Opportunity::query()->where('branch_id', $branchId);
        $stageCounts = (clone $query)->selectRaw('stage, COUNT(*) as total')->groupBy('stage')->pluck('total', 'stage');
        $open = (clone $query)->whereIn('stage', $openStages);
        $activities = Activity::query()->whereHas('opportunity', fn ($q) => $q->where('branch_id', $branchId));
        $overdue = (clone $activities)->whereNull('completed_at')->where('due_at', '<', now())->count();
        $today = (clone $activities)->whereNull('completed_at')->whereDate('due_at', today())->count();

        return view('Crm::dashboard', [
            'stageCounts' => $stageCounts,
            'openCount' => (clone $open)->count(),
            'pipelineValue' => (string) (clone $open)->sum('expected_value'),
            'weightedValue' => (string) (clone $open)->selectRaw('COALESCE(SUM(expected_value * probability / 100), 0) as total')->value('total'),
            'overdueCount' => $overdue,
            'todayCount' => $today,
            'wonMonthCount' => (clone $query)->where('stage', 'WON')->whereBetween('won_at', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            'noNextActionCount' => (clone $open)->whereNull('next_action_at')->count(),
            'nextActions' => (clone $open)->with(['party:id,code,name', 'owner:id,name'])->whereNotNull('next_action_at')->orderBy('next_action_at')->limit(8)->get(),
        ]);
    }
}
