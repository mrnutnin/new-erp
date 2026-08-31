<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Warehouse;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Requests\SaveBranchRequest;
use App\Modules\Settings\Rules\BranchStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

class BranchController extends Controller
{
    public function index(): View
    {
        return view('Settings::branches.index');
    }

    public function data(Request $request): JsonResponse
    {
        $dataTable = DataTables::eloquent($this->branchesQuery())
            ->filter(fn (Builder $query) => $this->applyTableSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyTableOrder($query, $request));

        if ($request->user()->hasPermission('settings.branches.update')) {
            $dataTable->addColumn('edit_url', fn (Branch $branch) => route('settings.branches.edit', $branch));
        }

        if ($request->user()->hasPermission('settings.branches.delete')) {
            $dataTable->addColumn('delete_url', fn (Branch $branch) => route('settings.branches.destroy', $branch));
        }

        return $dataTable->toJson();
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->branchesQuery();
        $this->applyTableSearch($query, $request);
        $this->applyTableOrder($query, $request);

        return response()->streamDownload(function () use ($query) {
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<?mso-application progid="Excel.Sheet"?>';
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Branches"><Table>';
            echo $this->excelRow(['รหัส', 'ชื่อสาขา', 'คลังที่ใช้งาน', 'สถานะ']);

            foreach ($query->lazy(500) as $branch) {
                echo $this->excelRow([
                    $branch->code,
                    $branch->name,
                    $branch->active_warehouses_count,
                    $branch->is_active ? 'ใช้งาน' : 'ปิดใช้งาน',
                ]);
            }

            echo '</Table></Worksheet></Workbook>';
        }, 'branches-'.now()->format('Ymd-His').'.xls', [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    public function create(): View
    {
        return view('Settings::branches.form', ['branch' => new Branch(['is_active' => true])]);
    }

    public function store(SaveBranchRequest $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $branch = DB::transaction(function () use ($audit, $request) {
            $branch = Branch::query()->create($request->validated());
            $audit->record('settings.branch.created', $branch, [], $request->validated(), $request->user(), $request);

            return $branch;
        });

        return $this->savedResponse($request, 'เพิ่มสาขาแล้ว', $branch);
    }

    public function edit(Branch $branch): View
    {
        return view('Settings::branches.form', compact('branch'));
    }

    public function update(SaveBranchRequest $request, Branch $branch, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        DB::transaction(function () use ($audit, $request, $branch) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branch->id);
            $activeWarehouseCount = Warehouse::query()
                ->whereBelongsTo($branch)
                ->where('is_active', true)
                ->count();

            if (! $request->boolean('is_active') && ! BranchStatus::canDeactivate($activeWarehouseCount)) {
                throw ValidationException::withMessages([
                    'is_active' => 'ต้องปิดคลังที่ยังใช้งานในสาขานี้ก่อน',
                ]);
            }

            $before = $branch->only(array_keys($request->validated()));
            $branch->update($request->validated());
            $audit->record('settings.branch.updated', $branch, $before, $request->validated(), $request->user(), $request);
        });

        return $this->savedResponse($request, 'แก้ไขสาขาแล้ว', $branch);
    }

    public function destroy(Request $request, Branch $branch, AuditLogger $audit): JsonResponse
    {
        $deleted = DB::transaction(function () use ($audit, $request, $branch) {
            $branch = Branch::query()->lockForUpdate()->findOrFail($branch->id);

            if (! BranchStatus::canDelete($branch->warehouses()->count())) {
                return false;
            }

            $before = $branch->only(['code', 'name', 'is_active']);
            $branch->delete();
            $audit->record('settings.branch.deleted', $branch, $before, ['deleted_at' => $branch->deleted_at], $request->user(), $request);

            return true;
        });

        if (! $deleted) {
            return response()->json([
                'status' => false,
                'msg' => 'ต้องลบคลังทั้งหมดในสาขานี้ก่อน',
            ], 409);
        }

        return response()->json(['status' => true, 'msg' => 'ลบสาขาแล้ว']);
    }

    private function savedResponse(SaveBranchRequest $request, string $message, Branch $branch): JsonResponse|RedirectResponse
    {
        $redirect = route('settings.branches.edit', $branch);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'msg' => $message,
                'redirect' => $redirect,
            ]);
        }

        return redirect($redirect)->with('success', $message);
    }

    private function branchesQuery(): Builder
    {
        return Branch::query()
            ->select(['branches.id', 'branches.code', 'branches.name', 'branches.is_active'])
            ->withCount(['warehouses as active_warehouses_count' => fn ($query) => $query->where('is_active', true)]);
    }

    private function applyTableSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('branches.code', 'like', "%{$search}%")
                ->orWhere('branches.name', 'like', "%{$search}%"));
        }
    }

    private function applyTableOrder(Builder $query, Request $request): void
    {
        $columns = [
            0 => 'branches.code',
            1 => 'branches.name',
            2 => 'active_warehouses_count',
            3 => 'branches.is_active',
        ];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'branches.code';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($column, $direction)->orderBy('branches.id');
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
