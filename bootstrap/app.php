<?php

use App\Http\Middleware\EnsurePermission;
use App\Modules\Platform\Middleware\EnsureBranchSelected;
use App\Modules\Platform\Middleware\EnsureModuleCapability;
use App\Modules\Platform\Middleware\EnsureProgramSelected;
use App\Modules\Platform\Middleware\EnsureWarehouseSelected;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'program' => EnsureProgramSelected::class,
            'branch' => EnsureBranchSelected::class,
            'capability' => EnsureModuleCapability::class,
            'warehouse' => EnsureWarehouseSelected::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
