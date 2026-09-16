<?php

namespace App\Modules\Platform\Controllers;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Modules\Platform\Requests\UpdatePasswordRequest;
use App\Modules\Platform\Requests\UpdateProfileRequest;
use App\Modules\Platform\Services\AuditLogger;
use App\Modules\Platform\Services\UserMediaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Yajra\DataTables\Facades\DataTables;

class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user()->load([
            'primaryBranch:id,code,name',
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

    public function image(Request $request, UserMediaService $media): StreamedResponse
    {
        return $media->inlineProfile($request->user());
    }

    public function signature(Request $request, UserMediaService $media): StreamedResponse
    {
        return $media->inlineSignature($request->user());
    }

    public function update(UpdateProfileRequest $request, AuditLogger $audit, UserMediaService $media): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        $media->persist(
            $user,
            $request->file('profile_image'),
            $request->boolean('remove_profile_image'),
            $request->file('signature_image'),
            $request->input('signature_data'),
            $request->boolean('remove_signature'),
            function (array $mediaValues) use ($audit, $request, $user): void {
                DB::transaction(function () use ($audit, $mediaValues, $request, $user): void {
                    $before = [
                        ...$user->only(['name', 'email']),
                        'has_profile_image' => filled($user->profile_image_path),
                        'has_signature' => filled($user->signature_path),
                    ];
                    $user->update([...$request->safe()->only(['name', 'email']), ...$mediaValues]);
                    $audit->record('platform.profile.updated', $user, $before, [
                        ...$user->fresh()->only(['name', 'email']),
                        'profile_image_changed' => $request->hasFile('profile_image') || $request->boolean('remove_profile_image'),
                        'signature_changed' => $request->hasFile('signature_image') || $request->filled('signature_data') || $request->boolean('remove_signature'),
                    ], $user, $request);
                });
            },
        );

        if ($request->expectsJson()) {
            return response()->json(['status' => true, 'msg' => 'บันทึกข้อมูลส่วนตัวแล้ว']);
        }

        return back()->with('success', 'บันทึกข้อมูลส่วนตัวแล้ว');
    }

    public function updatePassword(UpdatePasswordRequest $request, AuditLogger $audit): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($audit, $request, $user): void {
            $user->update(['password' => $request->validated('password')]);
            $audit->record('platform.profile.password_updated', $user, [], [
                'password_changed' => true,
            ], $user, $request);
        });

        if ($request->expectsJson()) {
            return response()->json(['status' => true, 'msg' => 'เปลี่ยนรหัสผ่านแล้ว']);
        }

        return back()->with('success', 'เปลี่ยนรหัสผ่านแล้ว');
    }
}
