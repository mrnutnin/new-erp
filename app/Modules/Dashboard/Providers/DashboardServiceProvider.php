<?php

namespace App\Modules\Dashboard\Providers;

use Illuminate\Support\ServiceProvider;

class DashboardServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['router']->middleware('web')->group(__DIR__.'/../Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../Views', 'Dashboard');
    }
}
