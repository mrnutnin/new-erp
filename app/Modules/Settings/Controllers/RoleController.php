<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Settings\Requests\SaveRoleRequest;
use App\Modules\Settings\Rules\RoleGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

class RoleController extends Controller
{
    public function __construct(private readonly RoleGuard $roleGuard) {}

    public function index(): View
    {
        return view('Settings::roles.index');
    }

    public function data(Request $request): JsonResponse
    {
        $dataTable = DataTables::eloquent($this->rolesQuery())
            ->filter(fn (Builder $query) => $this->applyTableSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyTableOrder($query, $request));

        if ($request->user()->hasPermission('settings.roles.manage')) {
            $dataTable->addColumn('edit_url', fn (Role $role) => route('settings.roles.edit', $role));
        }

        if ($request->user()->hasPermission('settings.roles.delete')) {
            $dataTable->addColumn('delete_url', fn (Role $role) => $role->code === 'admin'
                ? null
                : route('settings.roles.destroy', $role));
        }

        return $dataTable->toJson();
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->rolesQuery();
        $this->applyTableSearch($query, $request);
        $this->applyTableOrder($query, $request);

        return response()->streamDownload(function () use ($query) {
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<?mso-application progid="Excel.Sheet"?>';
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Roles"><Table>';
            echo $this->excelRow(['รหัส', 'ชื่อบทบาท', 'สิทธิ์', 'ผู้ใช้', 'สถานะ']);

            foreach ($query->lazy(500) as $role) {
                echo $this->excelRow([
                    $role->code,
                    $role->name,
                    $role->permissions_count,
                    $role->users_count,
                    $role->is_active ? 'ใช้งาน' : 'ปิดใช้งาน',
                ]);
            }

            echo '</Table></Worksheet></Workbook>';
        }, 'roles-'.now()->format('Ymd-His').'.xls', [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    public function create(): View
    {
        return $this->formView(new Role(['is_active' => true]));
    }

    public function store(SaveRoleRequest $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $role = DB::transaction(function () use ($audit, $request) {
            $role = Role::query()->create($request->safe()->except('permission_ids'));
            $role->permissions()->sync($request->input('permission_ids', []));
            $audit->record('settings.role.created', $role, [], $request->validated(), $request->user(), $request);

            return $role;
        });

        return $this->savedResponse($request, 'เพิ่มบทบาทแล้ว', $role);
    }

    public function edit(Role $role): View
    {
        $role->load('permissions:id');

        return $this->formView($role);
    }

    public function update(SaveRoleRequest $request, Role $role, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        if (! $this->roleGuard->canChangeCode($role->code, $request->string('code')->toString())) {
            throw ValidationException::withMessages(['code' => 'ไม่สามารถแก้ไขรหัสของบทบาทผู้ดูแลระบบได้']);
        }

        if (! $this->roleGuard->canSetActive($role->code, $request->boolean('is_active'))) {
            throw ValidationException::withMessages(['is_active' => 'ไม่สามารถปิดบทบาทผู้ดูแลระบบได้']);
        }

        DB::transaction(function () use ($audit, $request, $role) {
            $before = [
                ...$role->only(['code', 'name', 'description', 'is_active']),
                'permission_ids' => $role->permissions()->pluck('permissions.id')->all(),
            ];
            $role->update($request->safe()->except('permission_ids'));
            $permissionIds = $role->code === 'admin'
                ? Permission::query()->pluck('id')
                : $request->input('permission_ids', []);
            $role->permissions()->sync($permissionIds);
            $audit->record('settings.role.updated', $role, $before, [
                ...$request->safe()->except('permission_ids'),
                'permission_ids' => $permissionIds,
            ], $request->user(), $request);
        });

        return $this->savedResponse($request, 'แก้ไขบทบาทแล้ว', $role);
    }

    public function destroy(Request $request, Role $role, AuditLogger $audit): JsonResponse
    {
        $deleted = DB::transaction(function () use ($audit, $request, $role) {
            $role = Role::query()->lockForUpdate()->findOrFail($role->id);
            $userCount = $role->users()->count();

            if (! $this->roleGuard->canDelete($role->code, $userCount)) {
                return false;
            }

            $before = [
                ...$role->only(['code', 'name', 'description', 'is_active']),
                'permission_ids' => $role->permissions()->pluck('permissions.id')->all(),
            ];
            $role->delete();
            $audit->record('settings.role.deleted', $role, $before, ['deleted_at' => $role->deleted_at], $request->user(), $request);

            return true;
        });

        if (! $deleted) {
            return response()->json([
                'status' => false,
                'msg' => 'ไม่สามารถลบบทบาทผู้ดูแลระบบหรือบทบาทที่ยังมีผู้ใช้งานได้',
            ], 409);
        }

        return response()->json(['status' => true, 'msg' => 'ลบบทบาทแล้ว']);
    }

    private function formView(Role $role): View
    {
        $permissionGroups = Permission::query()
            ->orderBy('code')
            ->get()
            ->groupBy(fn (Permission $permission) => Str::beforeLast($permission->code, '.'));

        return view('Settings::roles.form', [
            'role' => $role,
            'permissionGroups' => $permissionGroups,
            'selectedPermissions' => $role->exists ? $role->permissions->pluck('id')->all() : [],
        ]);
    }

    private function savedResponse(SaveRoleRequest $request, string $message, Role $role): JsonResponse|RedirectResponse
    {
        $redirect = route('settings.roles.edit', $role);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'msg' => $message,
                'redirect' => $redirect,
            ]);
        }

        return redirect($redirect)->with('success', $message);
    }

    private function rolesQuery(): Builder
    {
        return Role::query()
            ->select(['roles.id', 'roles.code', 'roles.name', 'roles.is_active'])
            ->withCount(['users', 'permissions']);
    }

    private function applyTableSearch(Builder $query, Request $request): void
    {
        $search = trim((string) $request->input('search.value', ''));

        if ($search !== '') {
            $query->where(fn (Builder $query) => $query
                ->where('roles.code', 'like', "%{$search}%")
                ->orWhere('roles.name', 'like', "%{$search}%"));
        }
    }

    private function applyTableOrder(Builder $query, Request $request): void
    {
        $columns = [
            0 => 'roles.name',
            1 => 'permissions_count',
            2 => 'users_count',
            3 => 'roles.is_active',
        ];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'roles.name';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($column, $direction)->orderBy('roles.id');
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
