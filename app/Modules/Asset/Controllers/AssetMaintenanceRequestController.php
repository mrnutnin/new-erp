<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Party;
use App\Models\User;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetMaintenanceRequest;
use App\Modules\Asset\Requests\StoreAssetMaintenanceRequest;
use App\Modules\Asset\Services\AssetMaintenanceService;
use App\Modules\Finance\Models\DocumentSequence;
use App\Modules\Finance\Services\DocumentSequenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

final class AssetMaintenanceRequestController extends Controller
{
    public function index(): View
    {
        return view('Asset::maintenance.index');
    }

    public function data(Request $request): JsonResponse
    {
        $filters = $request->validate(['date_from' => ['nullable', 'date'], 'date_to' => ['nullable', 'date', 'after_or_equal:date_from'], 'asset_id' => ['nullable', 'integer', 'exists:assets,id']]);
        $query = AssetMaintenanceRequest::query()->with(['asset:id,asset_number,name', 'vendor:id,code,name', 'assignedTo:id,name'])->where('branch_id', $this->branchId($request))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->when($request->filled('priority'), fn ($query) => $query->where('priority', $request->string('priority')->toString()))
            ->when($filters['asset_id'] ?? null, fn ($query, $id) => $query->where('asset_id', $id))
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('reported_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('reported_date', '<=', $date))
            ->latest('reported_date')->latest('id');

        return DataTables::eloquent($query)
            ->addColumn('reported_date_label', fn (AssetMaintenanceRequest $maintenance) => $maintenance->reported_date?->format('d/m/Y') ?? '-')
            ->addColumn('asset_label', fn (AssetMaintenanceRequest $maintenance) => $maintenance->asset?->asset_number.' · '.$maintenance->asset?->name)
            ->addColumn('assigned_to_label', fn (AssetMaintenanceRequest $maintenance) => $maintenance->assignedTo?->name ?? '-')
            ->addColumn('show_url', fn (AssetMaintenanceRequest $maintenance) => route('asset.maintenance.show', $maintenance))->toJson();
    }

    public function create(): View
    {
        return view('Asset::maintenance.form');
    }

    public function store(StoreAssetMaintenanceRequest $request, AssetMaintenanceService $service, DocumentSequenceService $sequences): JsonResponse
    {
        $maintenance = DB::transaction(function () use ($request, $service, $sequences): AssetMaintenanceRequest {
            $branch = $request->attributes->get('selectedBranch');
            $date = Carbon::parse($request->validated('reported_date'));
            $sequence = DocumentSequence::query()->whereNull('warehouse_id')->where('document_type', 'ASSET_MAINTENANCE')->where('is_active', true)->lockForUpdate()->first();
            if (! $sequence) {
                throw ValidationException::withMessages(['reported_date' => 'ยังไม่ได้ตั้งค่าเลขที่ใบแจ้งซ่อมสินทรัพย์']);
            }
            $number = $sequences->issueAvailableForBranch($sequence, $branch, $date, fn (string $candidate): bool => AssetMaintenanceRequest::query()->where('document_number', $candidate)->exists());
            $maintenance = $service->create($branch, [...$request->validated(), 'document_number' => $number], $request->user());
            $sequences->recordIssued($sequence, $number, 'asset_maintenance_requests', $maintenance->id, $date, $request->user()->id);

            return $maintenance;
        });

        return response()->json(['status' => true, 'msg' => 'สร้างใบแจ้งซ่อมแล้ว', 'redirect' => route('asset.maintenance.show', $maintenance)]);
    }

    public function show(Request $request, AssetMaintenanceRequest $maintenance): View
    {
        return view('Asset::maintenance.show', [
            'maintenance' => $this->scoped($request, $maintenance)->load(['asset', 'branch', 'vendor', 'reportedBy', 'assignedTo', 'assignedBy', 'startedBy', 'completedBy', 'cancelledBy']),
            'assignees' => User::query()->where('is_active', true)->select(['id', 'name', 'employee_code'])->orderBy('name')->limit(30)->get(),
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $type = $request->string('type')->toString();
        $branchId = $this->branchId($request);
        $search = trim($request->string('q')->toString());
        $page = max(1, $request->integer('page', 1));
        $query = match ($type) {
            'asset' => Asset::query()->where('branch_id', $branchId)->whereNotIn('status', ['HELD_FOR_DISPOSAL', 'DISPOSED', 'WRITTEN_OFF'])->select(['id', 'asset_number', 'name']),
            'vendor' => Party::query()->where('is_active', true)->whereHas('roles', fn ($query) => $query->where('role', 'SUPPLIER')->where('is_active', true))->select(['id', 'code', 'name']),
            'assignee' => User::query()->where('is_active', true)->select(['id', 'name', 'employee_code']),
            default => abort(404),
        };
        if ($search !== '') {
            $query->where(fn ($query) => $query->where($type === 'asset' ? 'asset_number' : ($type === 'assignee' ? 'employee_code' : 'code'), 'like', "%{$search}%")->orWhere('name', 'like', "%{$search}%"));
        }
        $rows = $query->orderBy('name')->forPage($page, 31)->get();

        return response()->json(['results' => $rows->take(30)->map(function ($row) {
            $code = $row->asset_number ?? $row->code ?? $row->employee_code;

            return ['id' => $row->id, 'text' => $code ? $code.' · '.$row->name : $row->name];
        })->values(), 'pagination' => ['more' => $rows->count() > 30]]);
    }

    public function assign(Request $request, AssetMaintenanceRequest $maintenance, AssetMaintenanceService $service): JsonResponse
    {
        $data = $request->validate(['assigned_to_user_id' => ['required', 'integer', 'exists:users,id']]);

        return $this->changed($service->assign($this->scoped($request, $maintenance), $data['assigned_to_user_id'], $request->user()), 'มอบหมายงานซ่อมแล้ว');
    }

    public function start(Request $request, AssetMaintenanceRequest $maintenance, AssetMaintenanceService $service): JsonResponse
    {
        $data = $request->validate(['started_date' => ['required', 'date_format:Y-m-d']]);

        return $this->changed($service->start($this->scoped($request, $maintenance), $data, $request->user()), 'เริ่มดำเนินงานซ่อมแล้ว');
    }

    public function waitingParts(Request $request, AssetMaintenanceRequest $maintenance, AssetMaintenanceService $service): JsonResponse
    {
        return $this->changed($service->waitingParts($this->scoped($request, $maintenance), $request->user()), 'ตั้งสถานะรออะไหล่แล้ว');
    }

    public function complete(Request $request, AssetMaintenanceRequest $maintenance, AssetMaintenanceService $service): JsonResponse
    {
        $data = $request->validate(['completed_date' => ['required', 'date_format:Y-m-d'], 'diagnosis' => ['required', 'string', 'min:10'], 'resolution' => ['required', 'string', 'min:10'], 'downtime_minutes' => ['nullable', 'integer', 'min:0'], 'actual_cost' => ['nullable', 'numeric', 'min:0'], 'source_document_type' => ['nullable', 'string', 'max:50'], 'source_document_number' => ['nullable', 'string', 'max:100']]);

        return $this->changed($service->complete($this->scoped($request, $maintenance), $data, $request->user()), 'ปิดงานซ่อมแล้ว');
    }

    public function cancel(Request $request, AssetMaintenanceRequest $maintenance, AssetMaintenanceService $service): JsonResponse
    {
        $data = $request->validate(['cancellation_reason' => ['required', 'string', 'min:10', 'max:500']]);

        return $this->changed($service->cancel($this->scoped($request, $maintenance), $data['cancellation_reason'], $request->user()), 'ยกเลิกใบแจ้งซ่อมแล้ว');
    }

    private function changed(AssetMaintenanceRequest $maintenance, string $msg): JsonResponse
    {
        return response()->json(['status' => true, 'msg' => $msg, 'redirect' => route('asset.maintenance.show', $maintenance)]);
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }

    private function scoped(Request $request, AssetMaintenanceRequest $maintenance): AssetMaintenanceRequest
    {
        return AssetMaintenanceRequest::query()->where('branch_id', $this->branchId($request))->findOrFail($maintenance->id);
    }
}
