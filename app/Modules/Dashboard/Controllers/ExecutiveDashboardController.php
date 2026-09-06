<?php

namespace App\Modules\Dashboard\Controllers;

use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Modules\Dashboard\Requests\DashboardFilterRequest;
use App\Modules\Dashboard\Services\DashboardScopeService;
use App\Modules\Dashboard\Services\ExecutiveDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\View\View;

final class ExecutiveDashboardController extends Controller
{
    public function index(DashboardFilterRequest $request, DashboardScopeService $scope): View
    {
        return view('Dashboard::dashboard', [
            'company' => CompanySetting::query()->find(1),
            'branches' => $scope->branches($request->user()),
            'filters' => $request->validated(),
        ]);
    }

    public function data(DashboardFilterRequest $request, ExecutiveDashboardService $service): JsonResponse
    {
        return response()->json($service->snapshot($request->user(), $request->validated()));
    }
}
