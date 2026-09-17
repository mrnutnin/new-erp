<?php

namespace App\Modules\Crm\Providers;

use Illuminate\Support\ServiceProvider;

final class CrmServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['router']->middleware('web')->group(__DIR__.'/../Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../Views', 'Crm');
    }
}
