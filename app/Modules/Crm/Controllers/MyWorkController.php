<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Opportunity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class MyWorkController extends Controller
{
    private const OPEN_STAGES = ['NEW', 'CONTACTED', 'QUALIFIED', 'PROPOSAL', 'NEGOTIATION'];
    private const VIEWS = ['TODAY', 'OVERDUE', 'UPCOMING', 'NO_NEXT_ACTION', 'COMPLETED', 'ALL'];

    public function index(Request $request): View
    {
        $view = $this->viewFilter($request);
        $search = $this->search($request);
        $pending = $this->activityQuery($request)->whereNull('completed_at');
        $summary = (clone $pending)->selectRaw(
            'SUM(CASE WHEN DATE(due_at) = ? THEN 1 ELSE 0 END) today_count, '
            .'SUM(CASE WHEN due_at < ? THEN 1 ELSE 0 END) overdue_count, '
            .'SUM(CASE WHEN due_at > ? THEN 1 ELSE 0 END) upcoming_count',
            [today()->toDateString(), now(), today()->endOfDay()]
        )->first();
        $summary->no_next_count = $this->opportunityQuery($request)->whereNull('next_action_at')->count();
        $summary->completed_count = $this->activityQuery($request)->whereNotNull('completed_at')->count();

        return view('Crm::my-work.index', ['view' => $view, 'search' => $search, 'summary' => $summary]);
    }

    public function data(Request $request): JsonResponse
    {
        $view = $this->viewFilter($request);
        $search = $this->search($request);

        if ($view === 'NO_NEXT_ACTION') {
            $items = $this->opportunityQuery($request)
                ->with(['party:id,code,name,contact_name,phone,email', 'salesIntake:id,document_number'])
                ->whereNull('next_action_at')
                ->when($search !== '', fn (Builder $query) => $this->searchOpportunities($query, $search))
                ->orderByDesc('updated_at')->orderByDesc('id')
                ->paginate(12)->withPath(route('crm.my-work.index'))->withQueryString();
            $type = 'OPPORTUNITY';
        } else {
            $items = $this->activityQuery($request)
                ->with(['opportunity.party:id,code,name,contact_name,phone,email', 'opportunity.salesIntake:id,document_number'])
                ->when($view === 'COMPLETED', fn (Builder $query) => $query->whereNotNull('completed_at'), fn (Builder $query) => $query->whereNull('completed_at'))
                ->when($view === 'TODAY', fn (Builder $query) => $query->whereDate('due_at', today()))
                ->when($view === 'OVERDUE', fn (Builder $query) => $query->where('due_at', '<', now()))
                ->when($view === 'UPCOMING', fn (Builder $query) => $query->where('due_at', '>', today()->endOfDay()))
                ->when($search !== '', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                    ->where('subject', 'like', "%{$search}%")
                    ->orWhere('details', 'like', "%{$search}%")
                    ->orWhereHas('opportunity', fn (Builder $opportunity) => $this->searchOpportunities($opportunity, $search))))
                ->when($view === 'COMPLETED', fn (Builder $query) => $query->orderByDesc('completed_at'), fn (Builder $query) => $query->orderByRaw('due_at IS NULL')->orderBy('due_at'))
                ->orderByDesc('id')
                ->paginate(12)->withPath(route('crm.my-work.index'))->withQueryString();
            $type = 'ACTIVITY';
        }

        return response()->json([
            'html' => view('Crm::my-work._cards', ['items' => $items, 'type' => $type])->render(),
            'pagination' => $items->hasPages() ? $items->onEachSide(1)->links('pagination::bootstrap-5')->render() : '',
            'from' => $items->firstItem(),
            'to' => $items->lastItem(),
            'total' => $items->total(),
        ]);
    }

    private function activityQuery(Request $request): Builder
    {
        $branchId = (int) $request->attributes->get('selectedBranch')->id;

        return Activity::query()
            ->where('assigned_to', $request->user()->id)
            ->whereHas('opportunity', fn (Builder $query) => $query->where('branch_id', $branchId));
    }

    private function opportunityQuery(Request $request): Builder
    {
        return Opportunity::query()
            ->where('branch_id', (int) $request->attributes->get('selectedBranch')->id)
            ->where('owner_id', $request->user()->id)
            ->whereIn('stage', self::OPEN_STAGES);
    }

    private function searchOpportunities(Builder $query, string $search): Builder
    {
        return $query->where(fn (Builder $query) => $query
            ->where('title', 'like', "%{$search}%")
            ->orWhere('contact_name', 'like', "%{$search}%")
            ->orWhere('phone', 'like', "%{$search}%")
            ->orWhereHas('party', fn (Builder $party) => $party->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")));
    }

    private function search(Request $request): string
    {
        return trim((string) ($request->validate(['q' => ['nullable', 'string', 'max:100']])['q'] ?? ''));
    }

    private function viewFilter(Request $request): string
    {
        return $request->validate(['view' => ['nullable', Rule::in(self::VIEWS)]])['view'] ?? 'TODAY';
    }
}
