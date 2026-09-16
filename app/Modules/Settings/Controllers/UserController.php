<?php

namespace App\Modules\Settings\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Program;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\UserMediaService;
use App\Modules\Settings\Requests\SaveUserRequest;
use App\Modules\Settings\Rules\UserAccessGuard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

class UserController extends Controller
{
    public function index(): View
    {
        return view('Settings::users.index', [
            'branches' => Branch::query()->where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $dataTable = DataTables::eloquent($this->usersQuery())
            ->filter(fn (Builder $query) => $this->applyTableSearch($query, $request))
            ->order(fn (Builder $query) => $this->applyTableOrder($query, $request));

        if ($request->user()->hasPermission('settings.users.update')) {
            $dataTable->addColumn('edit_url', fn (User $user) => route('settings.users.edit', $user));
        }

        if ($request->user()->hasPermission('settings.users.delete')) {
            $dataTable->addColumn('delete_url', fn (User $user) => $request->user()->is($user) || $user->username === 'admin'
                ? null
                : route('settings.users.destroy', $user));
        }

        return $dataTable->toJson();
    }

    public function export(Request $request): StreamedResponse
    {
        $query = $this->usersQuery();
        $this->applyTableSearch($query, $request);
        $this->applyTableOrder($query, $request);

        return response()->streamDownload(function () use ($query) {
            echo '<?xml version="1.0" encoding="UTF-8"?>';
            echo '<?mso-application progid="Excel.Sheet"?>';
            echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Users"><Table>';
            echo $this->excelRow(['ชื่อ', 'รหัสพนักงาน', 'Username', 'Email', 'สาขาหลัก', 'สาขาที่เข้าใช้ได้', 'โปรแกรม', 'คลัง', 'สถานะ']);

            foreach ($query->lazy(500) as $user) {
                echo $this->excelRow([
                    $user->name,
                    $user->employee_code,
                    $user->username,
                    $user->email,
                    $user->primaryBranch?->name,
                    $user->branches_count,
                    $user->programs_count,
                    $user->warehouses_count,
                    $user->is_active ? 'ใช้งาน' : 'ปิดใช้งาน',
                ]);
            }

            echo '</Table></Worksheet></Workbook>';
        }, 'users-'.now()->format('Ymd-His').'.xls', [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    public function create(): View
    {
        return $this->formView(new User(['is_active' => true]));
    }

    public function store(SaveUserRequest $request, AuditLogger $audit, UserMediaService $media): JsonResponse|RedirectResponse
    {
        $user = new User;
        $mediaFields = ['profile_image', 'remove_profile_image', 'signature_image', 'signature_data', 'remove_signature'];
        $data = $request->safe()->except(['branch_ids', 'program_ids', 'warehouse_ids', 'role_ids', 'password_confirmation', ...$mediaFields]);

        $media->persist($user, $request->file('profile_image'), false, $request->file('signature_image'), $request->input('signature_data'), false, function (array $mediaValues) use ($audit, $data, $mediaFields, $request, $user): void {
            DB::transaction(function () use ($audit, $data, $mediaFields, $mediaValues, $request, $user): void {
                $user->fill([...$data, ...$mediaValues])->save();
                $this->syncAssignments($user, $request);
                $audit->record('settings.user.created', $user, [], [
                    ...$request->safe()->except(['password', 'password_confirmation', ...$mediaFields]),
                    'password_changed' => true,
                    'has_profile_image' => filled($user->profile_image_path),
                    'has_signature' => filled($user->signature_path),
                ], $request->user(), $request);
            });
        });

        return $this->savedResponse($request, 'เพิ่มผู้ใช้งานแล้ว', $user);
    }

    public function edit(User $user): View
    {
        $user->load(['branches:id', 'programs:id', 'warehouses:id', 'roles.permissions']);

        return $this->formView($user);
    }

    public function update(
        SaveUserRequest $request,
        User $user,
        UserAccessGuard $guard,
        AuditLogger $audit,
        UserMediaService $media,
    ): JsonResponse|RedirectResponse {
        $isSelf = $request->user()->is($user);

        if (! $guard->canSetActive($isSelf, $request->boolean('is_active'))) {
            throw ValidationException::withMessages(['is_active' => 'ไม่สามารถปิดผู้ใช้งานของตนเองได้']);
        }

        $settingsProgramId = Program::query()->where('code', 'settings')->value('id');
        $hasSettingsProgram = in_array($settingsProgramId, $request->input('program_ids', []));
        if (! $guard->canSetSettingsProgram($isSelf, $hasSettingsProgram)) {
            throw ValidationException::withMessages(['program_ids' => 'ไม่สามารถนำสิทธิ์ Settings ของตนเองออกได้']);
        }

        $adminRoleId = Role::query()->where('code', 'admin')->value('id');
        $currentlyAdmin = $user->roles()->where('roles.code', 'admin')->exists();
        $willBeAdmin = in_array($adminRoleId, $request->input('role_ids', []));
        if (! $guard->canSetAdminRole($isSelf, $currentlyAdmin, $willBeAdmin)) {
            throw ValidationException::withMessages(['role_ids' => 'ไม่สามารถนำบทบาทผู้ดูแลระบบของตนเองออกได้']);
        }

        $mediaFields = ['profile_image', 'remove_profile_image', 'signature_image', 'signature_data', 'remove_signature'];
        $media->persist(
            $user,
            $request->file('profile_image'),
            $request->boolean('remove_profile_image'),
            $request->file('signature_image'),
            $request->input('signature_data'),
            $request->boolean('remove_signature'),
            function (array $mediaValues) use ($audit, $mediaFields, $request, $user): void {
                DB::transaction(function () use ($audit, $mediaFields, $mediaValues, $request, $user): void {
                    $before = [
                        ...$user->only(['name', 'username', 'employee_code', 'position', 'email', 'is_active', 'primary_branch_id']),
                        'has_profile_image' => filled($user->profile_image_path),
                        'has_signature' => filled($user->signature_path),
                        'program_ids' => $user->programs()->pluck('programs.id')->all(),
                        'warehouse_ids' => $user->warehouses()->pluck('warehouses.id')->all(),
                        'role_ids' => $user->roles()->pluck('roles.id')->all(),
                        'branch_ids' => $user->branches()->pluck('branches.id')->all(),
                    ];
                    $data = $request->safe()->except(['branch_ids', 'program_ids', 'warehouse_ids', 'role_ids', 'password_confirmation', ...$mediaFields]);

                    if (blank($data['password'] ?? null)) {
                        unset($data['password']);
                    }

                    $user->update([...$data, ...$mediaValues]);
                    $this->syncAssignments($user, $request);
                    $audit->record('settings.user.updated', $user, $before, [
                        ...$request->safe()->except(['password', 'password_confirmation', ...$mediaFields]),
                        'password_changed' => $request->filled('password'),
                        'profile_image_changed' => $request->hasFile('profile_image') || $request->boolean('remove_profile_image'),
                        'signature_changed' => $request->hasFile('signature_image') || $request->filled('signature_data') || $request->boolean('remove_signature'),
                    ], $request->user(), $request);
                });
            },
        );

        return $this->savedResponse($request, 'แก้ไขผู้ใช้งานแล้ว', $user);
    }

    public function profileImage(User $user, UserMediaService $media): StreamedResponse
    {
        return $media->inlineProfile($user);
    }

    public function signature(User $user, UserMediaService $media): StreamedResponse
    {
        return $media->inlineSignature($user);
    }

    public function destroy(Request $request, User $user, UserAccessGuard $guard, AuditLogger $audit): JsonResponse
    {
        if (! $guard->canDelete($request->user()->is($user), $user->username === 'admin')) {
            return response()->json([
                'status' => false,
                'msg' => 'ไม่สามารถลบผู้ใช้งานของตนเองหรือบัญชีผู้ดูแลระบบหลักได้',
            ], 409);
        }

        DB::transaction(function () use ($audit, $request, $user) {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $before = $user->only(['name', 'username', 'employee_code', 'position', 'email', 'is_active', 'primary_branch_id']);
            $user->delete();
            $audit->record('settings.user.deleted', $user, $before, ['deleted_at' => $user->deleted_at], $request->user(), $request);
        });

        return response()->json(['status' => true, 'msg' => 'ลบผู้ใช้งานแล้ว']);
    }

    private function formView(User $user): View
    {
        $user->loadMissing('roles.permissions');
        $effectivePermissions = $user->roles
            ->where('is_active', true)
            ->flatMap(fn ($role) => $role->permissions)
            ->unique('id')
            ->sortBy('code')
            ->values();

        return view('Settings::users.form', [
            'user' => $user,
            'programs' => Program::query()->where('is_enabled', true)->orderBy('sort_order')->get(),
            'warehouses' => Warehouse::query()->where('is_active', true)->orderBy('name')->get(),
            'branches' => Branch::query()->where('is_active', true)->orderBy('name')->get(),
            'roles' => Role::query()->where('is_active', true)->orderBy('name')->get(),
            'selectedPrograms' => $user->exists ? $user->programs->pluck('id')->all() : [],
            'selectedBranches' => $user->exists ? $user->branches->pluck('id')->all() : [],
            'selectedWarehouses' => $user->exists ? $user->warehouses->pluck('id')->all() : [],
            'selectedRoles' => $user->exists ? $user->roles->pluck('id')->all() : [],
            'effectivePermissions' => $effectivePermissions,
        ]);
    }

    private function syncAssignments(User $user, SaveUserRequest $request): void
    {
        $user->branches()->sync($request->input('branch_ids', []));
        $user->programs()->sync($request->input('program_ids', []));
        $user->warehouses()->sync($request->input('warehouse_ids', []));
        $user->roles()->sync($request->input('role_ids', []));
    }

    private function savedResponse(SaveUserRequest $request, string $message, User $user): JsonResponse|RedirectResponse
    {
        $redirect = route('settings.users.edit', $user);

        if ($request->expectsJson()) {
            return response()->json([
                'status' => true,
                'msg' => $message,
                'redirect' => $redirect,
            ]);
        }

        return redirect($redirect)->with('success', $message);
    }

    private function usersQuery(): Builder
    {
        return User::query()
            ->select(['users.id', 'users.name', 'users.username', 'users.employee_code', 'users.email', 'users.is_active', 'users.primary_branch_id'])
            ->with(['primaryBranch:id,code,name'])
            ->withCount(['branches', 'programs', 'warehouses']);
    }

    private function applyTableSearch(Builder $query, Request $request): void
    {
        if (in_array($request->input('is_active'), ['0', '1'], true)) {
            $query->where('users.is_active', $request->boolean('is_active'));
        }

        if (ctype_digit((string) $request->input('primary_branch_id'))) {
            $query->where('users.primary_branch_id', $request->integer('primary_branch_id'));
        }

        $search = trim((string) $request->input('search.value', ''));

        if ($search === '') {
            return;
        }

        $query->where(function (Builder $query) use ($search) {
            $query->where('users.name', 'like', "%{$search}%")
                ->orWhere('users.username', 'like', "%{$search}%")
                ->orWhere('users.employee_code', 'like', "%{$search}%")
                ->orWhere('users.email', 'like', "%{$search}%")
                ->orWhereHas('primaryBranch', fn (Builder $branch) => $branch
                    ->where('branches.code', 'like', "%{$search}%")
                    ->orWhere('branches.name', 'like', "%{$search}%"));
        });
    }

    private function applyTableOrder(Builder $query, Request $request): void
    {
        $columns = [
            0 => 'users.name',
            2 => 'branches_count',
            3 => 'programs_count',
            4 => 'warehouses_count',
            5 => 'users.is_active',
        ];
        $column = $columns[(int) $request->input('order.0.column', 0)] ?? 'users.name';
        $direction = $request->input('order.0.dir') === 'desc' ? 'desc' : 'asc';

        $query->orderBy($column, $direction)->orderBy('users.id');
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
