<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Warehouse;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Requests\SaveWarehouseRequest;
use App\Modules\Settings\Rules\WarehouseStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

class WarehouseController extends Controller
{
    public function index(): View
    {
        return view('Settings::warehouses.index');
    }

    public function data(Request $request): JsonResponse
    {
        $dataTable = DataTables::eloquent($this->warehousesQuery())
            ->filter(fn (Builder $query) => $this->applyTableSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyTableOrder($query, $request));

        if ($request->user()->hasPermission('settings.warehouses.update')) {
            $dataTable->addColumn('edit_url', fn (Warehouse $warehouse) => route('settings.warehouses.edit', $warehouse));
        }

        if ($request->user()->hasPermission('settings.warehouses.delete')) {
            $dataTable->addColumn('delete_url', fn (Warehouse $warehouse) => route('settings.warehouses.destroy', $warehouse));
        }

        return $dataTable->toJson();
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->warehousesQuery();
        $this->applyTableSearch($query, $request);
        $this->applyTableOrder($query, $request);

        return response()->streamDownload(function () use ($query) {
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<?mso-application progid="Excel.Sheet"?>';
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Warehouses"><Table>';
            echo $this->excelRow(['รหัส', 'ชื่อคลัง', 'สาขา', 'สถานะ']);

            foreach ($query->lazy(500) as $warehouse) {
                echo $this->excelRow([
                    $warehouse->code,
                    $warehouse->name,
                    $warehouse->branch_code.' — '.$warehouse->branch_name,
                    $warehouse->is_active ? 'ใช้งาน' : 'ปิดใช้งาน',
                ]);
            }

            echo '</Table></Worksheet></Workbook>';
        }, 'warehouses-'.now()->format('Ymd-His').'.xls', [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    public function create(): View
    {
        return $this->formView(new Warehouse(['is_active' => true]));
    }

    public function store(SaveWarehouseRequest $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $warehouse = DB::transaction(function () use ($audit, $request) {
            $this->lockActiveBranch($request->integer('branch_id'));
            $warehouse = Warehouse::query()->create($request->validated());
            $audit->record('settings.warehouse.created', $warehouse, [], $request->validated(), $request->user(), $request);

            return $warehouse;
        });

        return $this->savedResponse($request, 'เพิ่มคลังแล้ว', $warehouse);
    }

    public function edit(Warehouse $warehouse): View
    {
        return $this->formView($warehouse);
    }

    public function update(SaveWarehouseRequest $request, Warehouse $warehouse, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        DB::transaction(function () use ($audit, $request, $warehouse) {
            $this->lockActiveBranch($request->integer('branch_id'));
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);
            $before = $warehouse->only(array_keys($request->validated()));
            $warehouse->update($request->validated());
            $audit->record('settings.warehouse.updated', $warehouse, $before, $request->validated(), $request->user(), $request);
        });

        return $this->savedResponse($request, 'แก้ไขคลังแล้ว', $warehouse);
    }

    public function destroy(Request $request, Warehouse $warehouse, AuditLogger $audit): JsonResponse
    {
        $deleted = DB::transaction(function () use ($audit, $request, $warehouse) {
            $warehouse = Warehouse::query()->lockForUpdate()->findOrFail($warehouse->id);

            if (! WarehouseStatus::canDelete($warehouse->is_active)) {
                return false;
            }

            $before = $warehouse->only(['branch_id', 'code', 'name', 'is_active']);
            $warehouse->delete();
            $audit->record('settings.warehouse.deleted', $warehouse, $before, ['deleted_at' => $warehouse->deleted_at], $request->user(), $request);

            return true;
        });

        if (! $deleted) {
            return response()->json([
                'status' => false,
                'msg' => 'ต้องปิดใช้งานคลังก่อนจึงจะลบได้',
            ], 409);
        }

        return response()->json(['status' => true, 'msg' => 'ลบคลังแล้ว']);
    }

    private function formView(Warehouse $warehouse): View
    {
        return view('Settings::warehouses.form', [
            'warehouse' => $warehouse,
            'branches' => Branch::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    private function lockActiveBranch(int $branchId): Branch
    {
        $branch = Branch::query()->lockForUpdate()->find($branchId);

        if (! $branch?->is_active) {
            throw ValidationException::withMessages(['branch_id' => 'สาขาที่เลือกไม่พร้อมใช้งาน']);
        }

        return $branch;
    }

    private function savedResponse(SaveWarehouseRequest $request, string $message, Warehouse $warehouse): JsonResponse|RedirectResponse
    {
        $redirect = route('settings.warehouses.edit', $warehouse);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'msg' => $message,
                'redirect' => $redirect,
            ]);
        }

        return redirect($redirect)->with('success', $message);
    }

    private function warehousesQuery(): Builder
    {
        return Warehouse::query()
            ->join('branches', 'branches.id', '=', 'warehouses.branch_id')
            ->whereNull('branches.deleted_at')
            ->select([
                'warehouses.id',
                'warehouses.code',
                'warehouses.name',
                'warehouses.is_active',
                'branches.code as branch_code',
                'branches.name as branch_name',
            ]);
    }

    private function applyTableSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('warehouses.code', 'like', "%{$search}%")
                ->orWhere('warehouses.name', 'like', "%{$search}%")
                ->orWhere('branches.code', 'like', "%{$search}%")
                ->orWhere('branches.name', 'like', "%{$search}%"));
        }
    }

    private function applyTableOrder(Builder $query, Request $request): void
    {
        $columns = [
            0 => 'warehouses.code',
            1 => 'warehouses.name',
            2 => 'branches.code',
            3 => 'warehouses.is_active',
        ];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'warehouses.code';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($column, $direction)->orderBy('warehouses.id');
    }

    /** @param array<int, int|string|null> $values */
    private function excelRow(array $values): string
    {
        $cells = array_map(function (int|string|null $value) {
            $type = is_int($value) ? 'Number' : 'String';
            $escaped = htmlspecialchars((string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8');

            return "<Cell><Data ss:Type=\"{$type}\">{$escaped}</Data></Cell>";
        }, $values);

        return '<Row>'.implode('', $cells).'</Row>';
    }
}
