<?php

namespace App\Modules\Asset\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Warehouse;
use App\Modules\Asset\Models\AssetLocation;
use App\Modules\Asset\Requests\SaveAssetLocationRequest;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class AssetLocationController extends Controller
{
    public function index(Request $request): View
    {
        return view('Asset::locations.index', ['branch' => $request->attributes->get('selectedBranch')]);
    }

    public function data(Request $request): JsonResponse
    {
        $table = DataTables::eloquent($this->query($this->branchId($request)))
            ->filter(fn (Builder $query) => $this->search($query, $request))
            ->order(fn (Builder $query) => $this->order($query, $request));

        if ($request->user()->hasPermission('asset.locations.manage')) {
            $table->addColumn('edit_url', fn (AssetLocation $location) => route('asset.locations.edit', $location));
            $table->addColumn('delete_url', fn (AssetLocation $location) => route('asset.locations.destroy', $location));
        }

        return $table->toJson();
    }

    public function create(Request $request): View
    {
        return $this->form(new AssetLocation(['branch_id' => $this->branchId($request), 'is_active' => true]), $request);
    }

    public function edit(Request $request, AssetLocation $assetLocation): View
    {
        return $this->form($this->forBranch($assetLocation, $request), $request);
    }

    public function store(SaveAssetLocationRequest $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $location = DB::transaction(function () use ($request, $audit) {
            $values = [...$request->validated(), 'created_by' => $request->user()->id, 'updated_by' => $request->user()->id];
            $location = AssetLocation::query()->create($values);
            $audit->record('asset.location.created', $location, [], $this->snapshot($location), $request->user(), $request);

            return $location;
        });

        return $this->saved($request, 'เพิ่มสถานที่สินทรัพย์แล้ว', $location);
    }

    public function update(SaveAssetLocationRequest $request, AssetLocation $assetLocation, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $location = $this->forBranch($assetLocation, $request);
        DB::transaction(function () use ($request, $location, $audit) {
            $location = AssetLocation::query()->lockForUpdate()->findOrFail($location->id);
            $before = $this->snapshot($location);
            $location->update([...$request->validated(), 'updated_by' => $request->user()->id]);
            $audit->record('asset.location.updated', $location, $before, $this->snapshot($location), $request->user(), $request);
        });

        return $this->saved($request, 'แก้ไขสถานที่สินทรัพย์แล้ว', $location);
    }

    public function destroy(Request $request, AssetLocation $assetLocation, AuditLogger $audit): JsonResponse
    {
        $location = $this->forBranch($assetLocation, $request);
        DB::transaction(function () use ($request, $location, $audit) {
            $location = AssetLocation::query()->lockForUpdate()->findOrFail($location->id);
            if ($location->assets()->exists() || $location->children()->exists()) {
                throw ValidationException::withMessages(['location' => 'สถานที่นี้ถูกใช้งานแล้ว ให้ปิดใช้งานหรือย้ายข้อมูลที่เกี่ยวข้องก่อน']);
            }

            $before = $this->snapshot($location);
            $location->delete();
            $audit->record('asset.location.deleted', $location, $before, ['deleted_at' => $location->deleted_at], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบสถานที่สินทรัพย์แล้ว']);
    }

    private function form(AssetLocation $assetLocation, Request $request): View
    {
        $branchId = $this->branchId($request);

        return view('Asset::locations.form', [
            'assetLocation' => $assetLocation,
            'branch' => $request->attributes->get('selectedBranch'),
            'parents' => AssetLocation::query()->where('branch_id', $branchId)->whereKeyNot($assetLocation->id ?: 0)->orderBy('code')->get(['id', 'code', 'name']),
            'warehouses' => Warehouse::query()->where('branch_id', $branchId)->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    private function query(int $branchId): Builder
    {
        return AssetLocation::query()->leftJoin('asset_locations as parents', 'parents.id', '=', 'asset_locations.parent_id')
            ->leftJoin('warehouses', 'warehouses.id', '=', 'asset_locations.warehouse_id')
            ->where('asset_locations.branch_id', $branchId)
            ->select(['asset_locations.id', 'asset_locations.code', 'asset_locations.name', 'asset_locations.location_type', 'asset_locations.is_active', 'parents.code as parent_code', 'parents.name as parent_name', 'warehouses.code as warehouse_code', 'warehouses.name as warehouse_name']);
    }

    private function search(Builder $query, Request $request): void
    {
        if ($request->filled('is_active')) {
            $query->where('asset_locations.is_active', $request->boolean('is_active'));
        }

        $search = trim((string) $request->input('search.value', ''));
        if ($search !== '') {
            $query->where(fn (Builder $q) => $q->where('asset_locations.code', 'like', "%{$search}%")->orWhere('asset_locations.name', 'like', "%{$search}%")->orWhere('parents.code', 'like', "%{$search}%")->orWhere('warehouses.code', 'like', "%{$search}%"));
        }
    }

    private function order(Builder $query, Request $request): void
    {
        $columns = [0 => 'asset_locations.code', 1 => 'asset_locations.name', 2 => 'asset_locations.location_type', 3 => 'parents.code', 4 => 'warehouses.code', 5 => 'asset_locations.is_active'];
        $query->orderBy($columns[(int) $request->input('order.0.column', 0)] ?? 'asset_locations.code', $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc')->orderBy('asset_locations.id');
    }

    private function branchId(Request $request): int
    {
        return (int) $request->attributes->get('selectedBranch')->id;
    }

    private function forBranch(AssetLocation $location, Request $request): AssetLocation
    {
        abort_unless((int) $location->branch_id === $this->branchId($request), 404);

        return $location;
    }

    private function saved(SaveAssetLocationRequest $request, string $message, AssetLocation $location): JsonResponse|RedirectResponse
    {
        $redirect = route('asset.locations.edit', $location);

        return $request->expectsJson() ? response()->json(['status' => true, 'msg' => $message, 'redirect' => $redirect]) : redirect($redirect)->with('success', $message);
    }

    private function snapshot(AssetLocation $location): array
    {
        return $location->only(['branch_id', 'parent_id', 'warehouse_id', 'code', 'name', 'location_type', 'address', 'is_active']);
    }
}
