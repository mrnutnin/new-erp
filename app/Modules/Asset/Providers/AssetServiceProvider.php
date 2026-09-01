<?php

namespace App\Modules\Asset\Providers;

use Illuminate\Support\ServiceProvider;

class AssetServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['router']->middleware('web')->group(__DIR__.'/../Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../Views', 'Asset');
    }
}
