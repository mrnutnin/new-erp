<?php

namespace App\Modules\Wms\Providers;

use App\Modules\Wms\Services\InventoryPostingPreflightReader;
use App\Modules\Wms\Services\InventoryPostingPreflightService;
use Illuminate\Support\ServiceProvider;

class WmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(
            InventoryPostingPreflightReader::class,
            InventoryPostingPreflightService::class,
        );
    }

    public function boot(): void
    {
        $this->app['router']->middleware('web')->group(__DIR__.'/../Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../Views', 'Wms');
    }
}
