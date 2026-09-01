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

        $label = $module === ModuleCapability::ASSET ? 'Asset' : 'Production';
        $message = "ยังไม่ได้เปิดใช้โมดูล {$label} สำหรับบริษัท กรุณาติดต่อผู้ดูแลระบบเพื่อเปิดใช้งาน";

        if ($request->expectsJson()) {
            return new JsonResponse(['status' => false, 'msg' => $message], 403);
        }

        return redirect()->route('settings.company.edit')->with('error', $message);
    }
}
