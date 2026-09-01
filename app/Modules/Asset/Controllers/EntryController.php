<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetDepreciationRun;
use App\Modules\Asset\Models\AssetDisposal;
use App\Modules\Asset\Models\AssetImpairment;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Asset\Services\AssetReconciliationService;
use App\Modules\Asset\Models\AssetMaintenanceRequest;
use App\Modules\Asset\Models\AssetMaintenanceSchedule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

class EntryController extends Controller
{
    public function __invoke(Request $request): View
    {
        return view('Asset::dashboard', [
            'program' => $request->attributes->get('selectedProgram'),
            'branch' => $request->attributes->get('selectedBranch'),
        ]);
    }

    public function maintenanceAlerts(Request $request): JsonResponse
    {
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $critical = AssetMaintenanceRequest::query()->with('asset:id,asset_number,name')->where('branch_id', $branchId)->where('priority', 'CRITICAL')->whereNotIn('status', ['COMPLETED', 'CANCELLED'])->latest('reported_date')->limit(5)->get();
        $due = AssetMaintenanceSchedule::query()->with('asset:id,asset_number,name')->where('branch_id', $branchId)->where('is_active', true)->whereDate('next_due_date', '<=', today()->addDays(7))->orderBy('next_due_date')->limit(5)->get();

        return response()->json(['critical' => $critical->map(fn ($item) => ['document_number' => $item->document_number, 'asset' => $item->asset?->asset_number.' · '.$item->asset?->name, 'url' => route('asset.maintenance.show', $item)])->values(), 'due' => $due->map(fn ($item) => ['title' => $item->title, 'asset' => $item->asset?->asset_number.' · '.$item->asset?->name, 'due_date' => $item->next_due_date->format('d/m/Y'), 'url' => route('asset.maintenance.schedules.edit', $item)])->values()]);
    }

    public function data(Request $request, string $section): JsonResponse
    {
        abort_unless(in_array($section, ['summary', 'maintenance', 'trend', 'controls', 'reconciliation'], true), 404);
        $branchId = (int) $request->attributes->get('selectedBranch')->id;
        $data = Cache::remember("asset:dashboard:{$section}:branch:{$branchId}", now()->addSeconds(30), fn () => match ($section) {
            'summary' => $this->summary($branchId),
            'maintenance' => $this->maintenance($branchId),
            'trend' => $this->trend($branchId),
            'controls' => $this->controls($branchId),
            'reconciliation' => $this->reconciliation($branchId),
        });

        return response()->json($data);
    }

    private function summary(int $branchId): array
    {
        $assets = Asset::query()->where('branch_id', $branchId)->selectRaw("COUNT(*) AS total, SUM(status = 'ACTIVE') AS active, SUM(status = 'UNDER_REPAIR') AS under_repair, SUM(status IN ('DISPOSED','WRITTEN_OFF')) AS retired, COALESCE(SUM(book_value),0) AS book_value")->first();
        $depreciation = AssetDepreciationRun::query()->where('branch_id', $branchId)->where('book_type', 'BOOK')->where('status', 'POSTED')->whereBetween('run_through_date', [now()->startOfMonth(), now()->endOfMonth()])->sum('total_depreciation');

        return ['total_assets' => (int) ($assets?->total ?? 0), 'active_assets' => (int) ($assets?->active ?? 0), 'under_repair' => (int) ($assets?->under_repair ?? 0), 'retired_assets' => (int) ($assets?->retired ?? 0), 'book_value' => (float) ($assets?->book_value ?? 0), 'monthly_depreciation' => (float) $depreciation];
    }

    private function maintenance(int $branchId): array
    {
        $requests = AssetMaintenanceRequest::query()->where('branch_id', $branchId)->selectRaw("SUM(status NOT IN ('COMPLETED','CANCELLED')) AS open_count, SUM(priority = 'CRITICAL' AND status NOT IN ('COMPLETED','CANCELLED')) AS critical_count, SUM(status = 'WAITING_PARTS') AS waiting_parts")->first();
        $due = AssetMaintenanceSchedule::query()->where('branch_id', $branchId)->where('is_active', true)->whereDate('next_due_date', '<=', today()->addDays(7))->count();

        return ['open_count' => (int) ($requests?->open_count ?? 0), 'critical_count' => (int) ($requests?->critical_count ?? 0), 'waiting_parts' => (int) ($requests?->waiting_parts ?? 0), 'due_schedules' => $due];
    }

    private function trend(int $branchId): array
    {
        $start = Carbon::today()->startOfMonth()->subMonths(5);
        $rows = AssetMaintenanceRequest::query()->where('branch_id', $branchId)->whereDate('reported_date', '>=', $start)->selectRaw("DATE_FORMAT(reported_date, '%Y-%m') AS period, COUNT(*) AS total")->groupBy('period')->pluck('total', 'period');
        $labels = [];
        $values = [];
        for ($i = 5; $i >= 0; $i--) {
            $period = Carbon::today()->startOfMonth()->subMonths($i);
            $key = $period->format('Y-m');
            $labels[] = $period->format('m/Y');
            $values[] = (int) ($rows[$key] ?? 0);
        }

        return ['labels' => $labels, 'values' => $values];
    }

    private function controls(int $branchId): array
    {
        $pendingDepreciation = AssetDepreciationRun::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])
            ->count();
        $pendingImpairment = AssetImpairment::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])
            ->count();
        $pendingDisposal = AssetDisposal::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', ['DRAFT', 'SUBMITTED', 'APPROVED'])
            ->count();
        $unlinkedPosted = AssetDepreciationRun::query()
            ->where('branch_id', $branchId)
            ->where('book_type', 'BOOK')
            ->where('status', 'POSTED')
            ->whereNull('journal_entry_id')
            ->count();

        return [
            'pending_depreciation' => $pendingDepreciation,
            'pending_impairment' => $pendingImpairment,
            'pending_disposal' => $pendingDisposal,
            'unlinked_posted' => $unlinkedPosted,
        ];
    }

    private function reconciliation(int $branchId): array
    {
        $period = FiscalPeriod::query()
            ->whereDate('start_date', '<=', today())
            ->whereDate('end_date', '>=', today())
            ->first()
            ?? FiscalPeriod::query()->orderByDesc('end_date')->first();

        if (! $period) {
            return ['period_name' => null, 'variance' => 0.0, 'status' => 'NO_PERIOD'];
        }

        $branch = \App\Models\Branch::query()->find($branchId);
        $totals = app(AssetReconciliationService::class)->totals($branch, $period);
        $variance = (float) $totals->variance;

        return [
            'period_name' => $period->name,
            'variance' => $variance,
            'status' => abs($variance) <= 0.005 ? 'OK' : 'VARIANCE',
        ];
    }
}
