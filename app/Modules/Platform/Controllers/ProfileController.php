<?php

namespace App\Modules\Platform\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Requests\UpdateProfileRequest;
use App\Modules\Platform\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(): View
    {
        return view('Platform::profile.edit');
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
