<?php

namespace App\Modules\Production\Providers;

use App\Modules\Production\Models\ProductionOrder;
use App\Modules\Production\Support\ProductionDemandQuery;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

final class ProductionServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['router']->middleware('web')->group(__DIR__.'/../Routes/web.php');
        $this->loadViewsFrom(__DIR__.'/../Views', 'Production');
        View::composer('Production::partials.sidebar', function ($view): void {
            $request = request();
            $branchId = (int) $request->attributes->get('selectedBranch')?->id;
            $warehouseId = (int) $request->attributes->get('selectedWarehouse')?->id;
            if (!$branchId) {
                $view->with(['productionDemandCount' => 0, 'productionDraftCount' => 0]);
                return;
            }

            $demandCount = ProductionDemandQuery::eligible($branchId)->count();

            $draftCount = ProductionOrder::query()->where('branch_id', $branchId)
                ->when($warehouseId, fn ($query) => $query->where('issue_warehouse_id', $warehouseId))
                ->where('status', 'DRAFT')->count();
            $view->with(['productionDemandCount' => $demandCount, 'productionDraftCount' => $draftCount]);
        });
    }
}
