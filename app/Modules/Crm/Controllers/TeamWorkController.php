<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Models\SalesTeam;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class TeamWorkController extends Controller
{
    private const OPEN_STAGES = ['NEW', 'CONTACTED', 'QUALIFIED', 'PROPOSAL', 'NEGOTIATION'];
    private const VIEWS = ['TODAY', 'OVERDUE', 'UPCOMING', 'NO_NEXT_ACTION', 'COMPLETED', 'ALL'];

    public function index(Request $request): View
    {
        $filters = $this->filters($request);
        $pending = $this->activities($request, $filters)->whereNull('completed_at');
        $summary = (clone $pending)->selectRaw('SUM(CASE WHEN DATE(due_at) = ? THEN 1 ELSE 0 END) today_count, SUM(CASE WHEN due_at < ? THEN 1 ELSE 0 END) overdue_count, SUM(CASE WHEN due_at > ? THEN 1 ELSE 0 END) upcoming_count', [today()->toDateString(), now(), today()->endOfDay()])->first();
        $summary->no_next_count = $this->opportunities($request, $filters)->whereNull('next_action_at')->count();
        $summary->completed_count = $this->activities($request, $filters)->whereNotNull('completed_at')->count();
        $memberIds = $this->memberIds($request, $filters);
        $workload = Activity::query()->join('crm_opportunities as opportunity', 'opportunity.id', '=', 'crm_activities.opportunity_id')
            ->join('users', 'users.id', '=', 'crm_activities.assigned_to')->whereNull('opportunity.deleted_at')->where('opportunity.branch_id', $this->branchId($request))->whereNull('crm_activities.completed_at')->when($memberIds !== null, fn ($query) => $query->whereIn('crm_activities.assigned_to', $memberIds))
            ->selectRaw('users.id, users.name, COUNT(*) open_count, SUM(CASE WHEN crm_activities.due_at < ? THEN 1 ELSE 0 END) overdue_count', [now()])
            ->groupBy('users.id', 'users.name')->orderByDesc('overdue_count')->orderByDesc('open_count')->limit(8)->get();

        return view('Crm::team-work.index', ['filters' => $filters, 'summary' => $summary, 'workload' => $workload, 'selectedUser' => $filters['user_id'] ? User::find($filters['user_id']) : null, 'teams' => $this->teams($request)->get(['id','name'])]);
    }

    public function data(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        if ($filters['view'] === 'NO_NEXT_ACTION') {
            $items = $this->opportunities($request, $filters)->with(['party:id,code,name,contact_name,phone,email', 'salesIntake:id,document_number', 'owner:id,name,employee_code'])
                ->whereNull('next_action_at')->when($filters['q'], fn (Builder $query, string $search) => $this->searchOpportunities($query, $search))
                ->orderByDesc('updated_at')->orderByDesc('id')->paginate(12)->withPath(route('crm.team-work.index'))->withQueryString();
            $type = 'OPPORTUNITY';
        } else {
            $view = $filters['view'];
            $items = $this->activities($request, $filters)->with(['assignee:id,name,employee_code', 'opportunity.owner:id,name,employee_code', 'opportunity.party:id,code,name,contact_name,phone,email', 'opportunity.salesIntake:id,document_number'])
                ->when($view === 'COMPLETED', fn (Builder $query) => $query->whereNotNull('completed_at'), fn (Builder $query) => $query->whereNull('completed_at'))
                ->when($view === 'TODAY', fn (Builder $query) => $query->whereDate('due_at', today()))
                ->when($view === 'OVERDUE', fn (Builder $query) => $query->where('due_at', '<', now()))
                ->when($view === 'UPCOMING', fn (Builder $query) => $query->where('due_at', '>', today()->endOfDay()))
                ->when($filters['q'], fn (Builder $query, string $search) => $query->where(fn (Builder $query) => $query->where('subject', 'like', "%{$search}%")->orWhere('details', 'like', "%{$search}%")->orWhereHas('opportunity', fn (Builder $opportunity) => $this->searchOpportunities($opportunity, $search))))
                ->when($view === 'COMPLETED', fn (Builder $query) => $query->orderByDesc('completed_at'), fn (Builder $query) => $query->orderByRaw('due_at IS NULL')->orderBy('due_at'))->orderByDesc('id')
                ->paginate(12)->withPath(route('crm.team-work.index'))->withQueryString();
            $type = 'ACTIVITY';
        }

        return response()->json(['html' => view('Crm::my-work._cards', ['items' => $items, 'type' => $type, 'teamView' => true])->render(), 'pagination' => $items->hasPages() ? $items->onEachSide(1)->links('pagination::bootstrap-5')->render() : '', 'from' => $items->firstItem(), 'to' => $items->lastItem(), 'total' => $items->total()]);
    }

    private function activities(Request $request, array $filters): Builder
    {
        $memberIds = $this->memberIds($request, $filters);
        return Activity::query()->whereHas('opportunity', fn (Builder $query) => $query->where('branch_id', $this->branchId($request)))
            ->when($memberIds !== null, fn (Builder $query) => $query->whereIn('assigned_to', $memberIds))->when($filters['user_id'], fn (Builder $query, int $id) => $query->where('assigned_to', $id))->when($filters['type'], fn (Builder $query, string $type) => $query->where('type', $type))
            ->when($filters['date_from'], fn (Builder $query, string $date) => $query->whereDate('due_at', '>=', $date))->when($filters['date_to'], fn (Builder $query, string $date) => $query->whereDate('due_at', '<=', $date));
    }

    private function opportunities(Request $request, array $filters): Builder
    {
        $memberIds = $this->memberIds($request, $filters);
        return Opportunity::query()->where('branch_id', $this->branchId($request))->whereIn('stage', self::OPEN_STAGES)->when($memberIds !== null, fn (Builder $query) => $query->whereIn('owner_id', $memberIds))->when($filters['user_id'], fn (Builder $query, int $id) => $query->where('owner_id', $id));
    }

    private function filters(Request $request): array
    {
        $values = $request->validate(['view' => ['nullable', Rule::in(self::VIEWS)], 'q' => ['nullable', 'string', 'max:100'], 'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)], 'team_id' => ['nullable', 'integer', 'exists:crm_sales_teams,id'], 'type' => ['nullable', Rule::in(['CALL', 'MEETING', 'TASK', 'NOTE'])], 'date_from' => ['nullable', 'date_format:Y-m-d'], 'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from']]);
        return ['view' => $values['view'] ?? 'TODAY', 'q' => trim((string) ($values['q'] ?? '')), 'user_id' => isset($values['user_id']) ? (int) $values['user_id'] : null, 'team_id' => isset($values['team_id']) ? (int) $values['team_id'] : null, 'type' => $values['type'] ?? null, 'date_from' => $values['date_from'] ?? null, 'date_to' => $values['date_to'] ?? null];
    }

    private function searchOpportunities(Builder $query, string $search): Builder
    {
        return $query->where(fn (Builder $query) => $query->where('title', 'like', "%{$search}%")->orWhere('contact_name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%")->orWhereHas('party', fn (Builder $party) => $party->where('code', 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%")));
    }

    private function teams(Request $request): Builder
    {
        return SalesTeam::query()->where('branch_id', $this->branchId($request))->where('is_active', true)
            ->when(! $request->user()->hasPermission('crm.team-work.view-all'), fn (Builder $query) => $query->where('manager_id', $request->user()->id));
    }

    private function memberIds(Request $request, array $filters): ?array
    {
        if ($request->user()->hasPermission('crm.team-work.view-all') && ! $filters['team_id']) return null;
        $team = $this->teams($request)->when($filters['team_id'], fn (Builder $query, int $id) => $query->whereKey($id))->first();
        return $team ? $team->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all() : [];
    }

    private function branchId(Request $request): int { return (int) $request->attributes->get('selectedBranch')->id; }
}
