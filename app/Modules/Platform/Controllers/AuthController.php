<?php

namespace App\Modules\Platform\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\Requests\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function create(): View
    {
        return view('Platform::auth.login');
    }

    public function store(LoginRequest $request): JsonResponse|RedirectResponse
    {
        $authenticated = Auth::attempt([
            'username' => $request->string('username')->toString(),
            'password' => $request->string('password')->toString(),
            'is_active' => true,
        ], $request->boolean('remember'));

        if (! $authenticated) {
            throw ValidationException::withMessages([
                'username' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
            ]);
        }

        $request->session()->regenerate();
        $request->session()->forget(['selected_program_id', 'selected_branch_id', 'selected_warehouse_id']);

        return $this->success($request, 'เข้าสู่ระบบสำเร็จ', route('programs.index'));
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $this->success($request, 'ออกจากระบบแล้ว', route('login'));
    }

    private function success(Request $request, string $message, string $redirect): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(compact('message', 'redirect'));
        }

        return redirect()->to($redirect)->with('success', $message);
    }
}
