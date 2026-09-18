<?php

namespace App\Modules\Crm\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Crm\Models\Activity;
use App\Modules\Crm\Models\Opportunity;
use App\Modules\Crm\Services\SalesForecastService;
use App\Modules\Platform\Services\SpreadsheetService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class ReportController extends Controller
{
    private const REPORTS = ['pipeline', 'sales', 'activities', 'losses'];

    public function index(Request $request, SalesForecastService $forecast): View
    {
        $filters = $this->filters($request);
        return view('Crm::reports.index', ['filters' => $filters, 'teams' => $forecast->teams($request), 'selectedOwner' => $filters['owner_id'] ? User::find($filters['owner_id']) : null]);
    }

    public function export(Request $request, SalesForecastService $forecast, SpreadsheetService $spreadsheet)
    {
        $filters = $this->filters($request);
        $data = match ($filters['report']) {
            'sales' => $this->sales($request, $forecast, $filters),
            'activities' => $this->activities($request, $filters),
            'losses' => $this->losses($request, $filters),
            default => $this->pipeline($request, $forecast, $filters),
        };
        $rows = collect($data['rows'] ?? [])->values();
        abort_if($rows->count() > 10000, 422, 'กรุณากรองข้อมูลให้ไม่เกิน 10,000 รายการ');
        $values = $rows->map(fn (array $row) => [$row['label'] ?? '-', $row['count'] ?? $row['opportunity_count'] ?? $row['planned'] ?? 0, $row['value'] ?? $row['pipeline'] ?? $row['actual'] ?? 0, $row['forecast'] ?? 0])->all();
        return $spreadsheet->download('crm-'.$filters['report'].'-'.$filters['month'].'-'.now()->format('Ymd-His').'.xlsx', [['title' => 'รายงาน CRM', 'headings' => ['รายการ', 'จำนวน', 'มูลค่า', 'Forecast'], 'rows' => $values]]);
    }

    public function data(Request $request, SalesForecastService $forecast): JsonResponse
    {
        $filters = $this->filters($request);
        $data = match ($filters['report']) {
            'sales' => $this->sales($request, $forecast, $filters),
            'activities' => $this->activities($request, $filters),
            'losses' => $this->losses($request, $filters),
            default => $this->pipeline($request, $forecast, $filters),
        };
        return response()->json(['report' => $filters['report'], 'month' => $filters['month'], 'data' => $data]);
    }

    private function sales(Request $request, SalesForecastService $forecast, array $filters): array
    {
        $snapshot = $forecast->snapshot($request, ['month' => $filters['month'], 'team_id' => $filters['team_id'], 'owner_id' => $filters['owner_id'], 'page' => 1]);
        return ['summary' => $snapshot['summary'], 'performance' => $snapshot['performance'], 'stages' => $snapshot['stages']->map(fn ($row) => ['stage' => $row->stage, 'count' => (int) $row->opportunity_count, 'pipeline' => (float) $row->pipeline, 'forecast' => (float) $row->forecast])->values(), 'rows' => $snapshot['paginator']->getCollection()->values()];
    }

    private function pipeline(Request $request, SalesForecastService $forecast, array $filters): array
    {
        [$from, $to, $owners] = $this->scope($request, $forecast, $filters);
        $query = Opportunity::query()->where('branch_id', $this->branch($request))->whereIn('stage', ['NEW', 'CONTACTED', 'QUALIFIED', 'PROPOSAL', 'NEGOTIATION'])->whereBetween('expected_close_date', [$from, $to])->when($owners !== null, fn ($q) => $q->whereIn('owner_id', $owners));
        $rows = (clone $query)->selectRaw('stage, COUNT(*) count, SUM(expected_value) pipeline, SUM(expected_value * probability / 100) forecast')->groupBy('stage')->orderByRaw("FIELD(stage,'NEW','CONTACTED','QUALIFIED','PROPOSAL','NEGOTIATION')")->get();
        $risk = (clone $query)->where(fn ($q) => $q->whereNull('next_action_at')->orWhere('next_action_at', '<', now())->orWhereDate('expected_close_date', '<', today()))->count();
        return ['summary' => ['count' => (clone $query)->count(), 'pipeline' => (float) $rows->sum('pipeline'), 'forecast' => (float) $rows->sum('forecast'), 'risk' => $risk], 'rows' => $rows->map(fn ($row) => ['label' => $row->stage, 'count' => (int) $row->count, 'pipeline' => (float) $row->pipeline, 'forecast' => (float) $row->forecast])->values()];
    }

    private function activities(Request $request, array $filters): array
    {
        [$from, $to, $owners] = $this->scope($request, app(SalesForecastService::class), $filters);
        $query = Activity::query()->whereBetween('due_at', [$from.' 00:00:00', $to.' 23:59:59'])->whereHas('opportunity', fn (Builder $q) => $q->where('branch_id', $this->branch($request)))->when($owners !== null, fn ($q) => $q->whereIn('assigned_to', $owners));
        $all = (clone $query)->get(['id', 'type', 'due_at', 'completed_at', 'assigned_to']);
        $summary = ['planned' => $all->count(), 'completed' => $all->whereNotNull('completed_at')->count(), 'overdue' => $all->filter(fn ($a) => !$a->completed_at && $a->due_at?->isPast())->count()];
        $summary['completion_rate'] = $summary['planned'] ? round($summary['completed'] / $summary['planned'] * 100, 1) : null;
        $rows = $all->groupBy('type')->map(fn ($items, $type) => ['label' => $type, 'planned' => $items->count(), 'completed' => $items->whereNotNull('completed_at')->count(), 'overdue' => $items->filter(fn ($a) => !$a->completed_at && $a->due_at?->isPast())->count()])->values();
        return compact('summary', 'rows');
    }

    private function losses(Request $request, array $filters): array
    {
        [$from, $to, $owners] = $this->scope($request, app(SalesForecastService::class), $filters);
        $query = Opportunity::query()->where('branch_id', $this->branch($request))->whereBetween('lost_at', [$from.' 00:00:00', $to.' 23:59:59'])->when($owners !== null, fn ($q) => $q->whereIn('owner_id', $owners));
        $rows = (clone $query)->selectRaw("COALESCE(NULLIF(TRIM(lost_reason),''),'ไม่ระบุเหตุผล') label, COUNT(*) count, SUM(expected_value) value")->groupBy('label')->orderByDesc('value')->limit(50)->get();
        return ['summary' => ['count' => (clone $query)->count(), 'value' => (float) $rows->sum('value'), 'unknown' => $rows->firstWhere('label', 'ไม่ระบุเหตุผล')?->count ?? 0], 'rows' => $rows->map(fn ($row) => ['label' => $row->label, 'count' => (int) $row->count, 'value' => (float) $row->value])->values()];
    }

    private function scope(Request $request, SalesForecastService $forecast, array $filters): array
    {
        $month = Carbon::createFromFormat('Y-m', $filters['month']);
        $teams = $forecast->teams($request);
        if ($filters['team_id']) { $team = $teams->firstWhere('id', $filters['team_id']); abort_unless($team, 403); $owners = $team->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all(); }
        elseif (!$request->user()->hasPermission('crm.team-work.view-all')) { $owners = $teams->flatMap(fn ($team) => $team->members()->pluck('users.id'))->push($request->user()->id)->unique()->map(fn ($id) => (int) $id)->all(); }
        else $owners = null;
        if ($filters['owner_id']) { abort_if($owners !== null && !in_array($filters['owner_id'], $owners, true), 403); $owners = [$filters['owner_id']]; }
        return [$month->toDateString(), $month->copy()->endOfMonth()->toDateString(), $owners];
    }

    private function filters(Request $request): array
    {
        $data = $request->validate(['report' => ['nullable', Rule::in(self::REPORTS)], 'month' => ['nullable', 'date_format:Y-m'], 'team_id' => ['nullable', 'integer'], 'owner_id' => ['nullable', 'integer'], 'tab' => ['nullable', Rule::in(self::REPORTS)]]);
        return ['report' => $data['report'] ?? $data['tab'] ?? 'pipeline', 'month' => $data['month'] ?? now()->format('Y-m'), 'team_id' => isset($data['team_id']) ? (int) $data['team_id'] : null, 'owner_id' => isset($data['owner_id']) ? (int) $data['owner_id'] : null];
    }

    private function branch(Request $request): int { return (int) $request->attributes->get('selectedBranch')->id; }
}
