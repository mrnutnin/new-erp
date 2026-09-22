<?php

namespace App\Modules\Production\Providers;

use App\Modules\Pos\Models\SalesOrderLine;
use App\Modules\Production\Models\ProductionOrder;
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

            $demandCount = SalesOrderLine::query()
                ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
                ->where('sales_orders.branch_id', $branchId)->where('sales_orders.status', 'CONFIRMED')
                ->whereExists(fn ($query) => $query->selectRaw('1')->from('production_boms')
                    ->join('production_bom_revisions', 'production_bom_revisions.bom_id', '=', 'production_boms.id')
                    ->whereColumn('production_boms.finished_item_id', 'sales_order_lines.item_id')
                    ->whereColumn('production_boms.base_uom_id', 'sales_order_lines.uom_id')
                    ->where('production_boms.branch_id', $branchId)->where('production_boms.is_active', true)
                    ->where('production_bom_revisions.status', 'ACTIVE'))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('production_orders')
                    ->whereColumn('production_orders.sales_order_line_id', 'sales_order_lines.id')
                    ->where('production_orders.status', '!=', 'CANCELLED')->whereNull('production_orders.deleted_at'))
                ->count();

            $draftCount = ProductionOrder::query()->where('branch_id', $branchId)
                ->when($warehouseId, fn ($query) => $query->where('issue_warehouse_id', $warehouseId))
                ->where('status', 'DRAFT')->count();
            $view->with(['productionDemandCount' => $demandCount, 'productionDraftCount' => $draftCount]);
        });
    }
}
