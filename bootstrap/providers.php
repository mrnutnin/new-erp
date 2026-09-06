<?php

use App\Modules\Accounting\Providers\AccountingServiceProvider;
use App\Modules\Asset\Providers\AssetServiceProvider;
use App\Modules\Dashboard\Providers\DashboardServiceProvider;
use App\Modules\Finance\Providers\FinanceServiceProvider;
use App\Modules\Platform\Providers\PlatformServiceProvider;
use App\Modules\Pos\Providers\PosServiceProvider;
use App\Modules\Purchasing\Providers\PurchasingServiceProvider;
use App\Modules\Settings\Providers\SettingsServiceProvider;
use App\Modules\Wms\Providers\WmsServiceProvider;
use App\Providers\AppServiceProvider;

return [
    AppServiceProvider::class,
    PlatformServiceProvider::class,
    DashboardServiceProvider::class,
    SettingsServiceProvider::class,
    AccountingServiceProvider::class,
    AssetServiceProvider::class,
    FinanceServiceProvider::class,
    WmsServiceProvider::class,
    PurchasingServiceProvider::class,
    PosServiceProvider::class,
];
