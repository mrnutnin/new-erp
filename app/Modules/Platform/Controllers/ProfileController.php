<?php

namespace App\Modules\Platform\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Platform\Requests\UpdateProfileRequest;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Yajra\DataTables\Facades\DataTables;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user()->load([
            'programs:id,code,name',
            'branches:id,code,name',
            'warehouses:id,branch_id,code,name',
            'roles.permissions',
        ]);
        $effectivePermissions = $user->roles
            ->where('is_active', true)
            ->flatMap(fn ($role) => $role->permissions)
            ->unique('id')
            ->sortBy('code')
            ->values();

        return view('Platform::profile.edit', compact('user', 'effectivePermissions'));
    }

    public function auditData(Request $request): JsonResponse
    {
        return DataTables::eloquent(
            AuditLog::query()->where('user_id', $request->user()->id)
        )
            ->addColumn('created_at_label', fn (AuditLog $log) => $log->created_at?->format('d/m/Y H:i') ?? '—')
            ->addColumn('subject_label', fn (AuditLog $log) => class_basename((string) $log->subject_type).($log->subject_id ? ' #'.$log->subject_id : ''))
            ->addColumn('reason_label', fn (AuditLog $log) => $log->reason ?? '—')
            ->toJson();
    }

    public function update(UpdateProfileRequest $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($audit, $request, $user) {
            $before = $user->only(['name', 'email']);
            $values = $request->safe()->only(['name', 'email', 'password']);
            if (blank($values['password'] ?? null)) {
                unset($values['password']);
            }

            $user->update($values);
            $audit->record('platform.profile.updated', $user, $before, [
                ...$user->fresh()->only(['name', 'email']),
                'password_changed' => $request->filled('password'),
            ], $user, $request);
        });

        if ($request->expectsJson()) {
            return response()->json(['status' => true, 'msg' => 'บันทึกข้อมูลส่วนตัวแล้ว']);
        }

        return back()->with('success', 'บันทึกข้อมูลส่วนตัวแล้ว');
    }
}
