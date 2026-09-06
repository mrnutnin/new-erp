<?php

namespace App\Modules\Installer\Providers;

use Illuminate\Support\ServiceProvider;

class InstallerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(config_path('erp.php'), 'erp');
    }

    public function boot(): void
    {
        $this->loadViewsFrom(__DIR__.'/../Views', 'Installer');
    }
}
