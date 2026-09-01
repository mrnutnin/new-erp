<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetMaintenanceSchedule;
use App\Modules\Asset\Requests\SaveAssetMaintenanceScheduleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class AssetMaintenanceScheduleController extends Controller
{
    public function index(): View
    {
        return view('Asset::maintenance.schedules.index');
    }

    public function create(): View
    {
        return view('Asset::maintenance.schedules.form', ['schedule' => new AssetMaintenanceSchedule(['next_due_date' => today(), 'default_priority' => 'NORMAL', 'is_active' => true])]);
    }

    public function data(Request $request): JsonResponse
    {
        $query = AssetMaintenanceSchedule::query()->with(['asset:id,asset_number,name', 'responsible:id,name'])->where('branch_id', $this->branchId($request))->when($request->filled('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))->latest('next_due_date')->latest('id');

        return DataTables::eloquent($query)->addColumn('asset_label', fn (AssetMaintenanceSchedule $schedule) => $schedule->asset?->asset_number.' · '.$schedule->asset?->name)->addColumn('responsible_label', fn (AssetMaintenanceSchedule $schedule) => $schedule->responsible?->name ?? '-')->addColumn('next_due_date_label', fn (AssetMaintenanceSchedule $schedule) => $schedule->next_due_date?->format('d/m/Y') ?? '-')->addColumn('due_state', fn (AssetMaintenanceSchedule $schedule) => $schedule->next_due_date->isPast() ? 'OVERDUE' : ($schedule->next_due_date->lessThanOrEqualTo(today()->addDays(7)) ? 'DUE_SOON' : 'UPCOMING'))->addColumn('edit_url', fn (AssetMaintenanceSchedule $schedule) => route('asset.maintenance.schedules.edit', $schedule))->addColumn('complete_url', fn (AssetMaintenanceSchedule $schedule) => $schedule->is_active && $request->user()->hasPermission('asset.maintenance.complete') ? route('asset.maintenance.schedules.complete', $schedule) : null)->toJson();
    }

    public function store(SaveAssetMaintenanceScheduleRequest $request): JsonResponse
    {
        $schedule = $this->save($request, new AssetMaintenanceSchedule);

        return response()->json(['status' => true, 'msg' => 'สร้างแผนบำรุงรักษาแล้ว', 'redirect' => route('asset.maintenance.schedules.index')]);
    }

    public function edit(Request $request, AssetMaintenanceSchedule $schedule): View
    {
        return view('Asset::maintenance.schedules.form', ['schedule' => $this->scoped($request, $schedule)]);
    }

    public function update(SaveAssetMaintenanceScheduleRequest $request, AssetMaintenanceSchedule $schedule): JsonResponse
    {
        $this->save($request, $this->scoped($request, $schedule));

        return response()->json(['status' => true, 'msg' => 'แก้ไขแผนบำรุงรักษาแล้ว']);
    }

    public function complete(Request $request, AssetMaintenanceSchedule $schedule): JsonResponse
    {
        $data = $request->validate(['completed_date' => ['required', 'date_format:Y-m-d']]);
        $schedule = DB::transaction(function () use ($request, $schedule, $data): AssetMaintenanceSchedule {
            $schedule = AssetMaintenanceSchedule::query()->where('branch_id', $this->branchId($request))->lockForUpdate()->findOrFail($schedule->id);
            if (! $schedule->is_active) {
                throw ValidationException::withMessages(['status' => 'ปิดแผนบำรุงรักษาอยู่ ไม่สามารถบันทึกดำเนินการได้']);
            }
            $date = Carbon::parse($data['completed_date']);
            $next = $schedule->interval_days ? $date->copy()->addDays($schedule->interval_days) : $date->copy()->addMonthsNoOverflow($schedule->interval_months);
            $schedule->update(['last_completed_date' => $date, 'last_completed_by' => $request->user()->id, 'next_due_date' => $next, 'last_alerted_at' => null, 'updated_by' => $request->user()->id]);

            return $schedule;
        });

        return response()->json(['status' => true, 'msg' => 'บันทึกการบำรุงรักษาแล้ว กำหนดครั้งถัดไป '.$schedule->next_due_date->format('d/m/Y')]);
    }

    private function save(SaveAssetMaintenanceScheduleRequest $request, AssetMaintenanceSchedule $schedule): AssetMaintenanceSchedule
    {
        return DB::transaction(function () use ($request, $schedule): AssetMaintenanceSchedule {
            $asset = Asset::query()->where('branch_id', $this->branchId($request))->lockForUpdate()->findOrFail($request->integer('asset_id'));
            if (in_array($asset->status, ['HELD_FOR_DISPOSAL', 'DISPOSED', 'WRITTEN_OFF'], true)) {
                throw ValidationException::withMessages(['asset_id' => 'สินทรัพย์สถานะนี้ไม่สามารถสร้างแผนบำรุงรักษาได้']);
            }
            $schedule->fill([...$request->validated(), 'branch_id' => $asset->branch_id, 'is_active' => $request->boolean('is_active')]);
            $schedule->created_by ??= $request->user()->id;
            $schedule->updated_by = $request->user()->id;
            $schedule->save();

            return $schedule;
        });
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }

    private function scoped(Request $request, AssetMaintenanceSchedule $schedule): AssetMaintenanceSchedule
    {
        return AssetMaintenanceSchedule::query()->where('branch_id', $this->branchId($request))->findOrFail($schedule->id);
    }
}
