<?php

namespace App\Modules\Purchasing\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Purchasing module boundary.
 *
 * The first extraction keeps the existing WMS-owned domain services as the
 * implementation source of truth. Canonical Purchasing routes are loaded
 * here so the namespace can be moved incrementally without breaking users.
 */
class PurchasingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['router']->middleware('web')->group(__DIR__.'/../Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../Views', 'Purchasing');
    }
}
