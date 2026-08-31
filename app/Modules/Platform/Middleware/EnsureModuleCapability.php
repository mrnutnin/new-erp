<?php

namespace App\Modules\Platform\Middleware;

use App\Modules\Platform\Services\ModuleCapability;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureModuleCapability
{
    public function __construct(private readonly ModuleCapability $capability) {}

    public function handle(Request $request, Closure $next, string $module): Response
    {
        if ($this->capability->isEnabled($module)) {
            return $next($request);
        }

        $message = 'ยังไม่ได้เปิดใช้โมดูลนี้สำหรับประเภทธุรกิจของบริษัท กรุณาติดต่อผู้ดูแลระบบเพื่อเปิดใช้งาน Production';

        if ($request->expectsJson()) {
            return new JsonResponse(['status' => false, 'msg' => $message], 403);
        }

        return redirect()->route('settings.company.edit')->with('error', $message);
    }
}
