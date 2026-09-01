<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Models\AssetMaintenanceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class AssetMaintenanceReportController extends Controller
{
    public function index(): View
    {
        return view('Asset::reports.maintenance');
    }

    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'status' => ['nullable', 'in:OPEN,ASSIGNED,IN_PROGRESS,WAITING_PARTS,COMPLETED,CANCELLED'], 'priority' => ['nullable', 'in:LOW,NORMAL,HIGH,CRITICAL'], 'asset_id' => ['nullable', 'integer', 'exists:assets,id']]);
        $query = AssetMaintenanceRequest::query()->with(['asset:id,asset_number,name', 'assignedTo:id,name'])->where('branch_id', $this->branchId($request))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('reported_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('reported_date', '<=', $date))
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['priority'] ?? null, fn ($query, $priority) => $query->where('priority', $priority))
            ->when($filters['asset_id'] ?? null, fn ($query, $assetId) => $query->where('asset_id', $assetId))
            ->latest('reported_date')->latest('id');
        $totals = ['count' => (clone $query)->count(), 'estimated_cost' => (float) (clone $query)->sum('estimated_cost'), 'actual_cost' => (float) (clone $query)->sum('actual_cost'), 'downtime_minutes' => (int) (clone $query)->sum('downtime_minutes')];

        return DataTables::eloquent($query)->with('totals', $totals)
            ->addColumn('reported_date_label', fn (AssetMaintenanceRequest $row) => $row->reported_date?->format('d/m/Y') ?? '-')
            ->addColumn('asset_label', fn (AssetMaintenanceRequest $row) => $row->asset?->asset_number.' · '.$row->asset?->name)
            ->addColumn('assigned_to_label', fn (AssetMaintenanceRequest $row) => $row->assignedTo?->name ?? '-')
            ->toJson();
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }
}
