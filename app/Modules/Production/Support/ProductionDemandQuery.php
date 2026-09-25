<?php

namespace App\Modules\Production\Support;

use App\Modules\Pos\Models\SalesOrderLine;
use Illuminate\Database\Eloquent\Builder;

final class ProductionDemandQuery
{
    public static function eligible(int $branchId): Builder
    {
        return SalesOrderLine::query()
            ->join('sales_orders', 'sales_orders.id', '=', 'sales_order_lines.sales_order_id')
            ->leftJoin('wms_items', 'wms_items.id', '=', 'sales_order_lines.item_id')
            ->leftJoin('wms_uoms', 'wms_uoms.id', '=', 'sales_order_lines.uom_id')
            ->where('sales_orders.branch_id', $branchId)
            ->where('sales_orders.status', 'CONFIRMED')
            ->whereNotNull('sales_order_lines.production_requested_at')
            ->whereNull('sales_orders.deleted_at')
            ->where(fn (Builder $q) => $q->where('sales_orders.production_legacy_eligible', true)->orWhere('wms_items.can_manufacture', true))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('pos_physical_sales')
                ->where('source_type', 'SALES_ORDER')->whereColumn('source_id', 'sales_orders.id')
                ->where('status', '!=', 'VOID')->whereNull('deleted_at'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('production_orders')
                ->whereColumn('production_orders.sales_order_line_id', 'sales_order_lines.id')
                ->where('production_orders.status', '!=', 'CANCELLED')->whereNull('production_orders.deleted_at'));
    }

    public static function bomReadySql(): string
    {
        return "EXISTS (SELECT 1 FROM production_boms b JOIN production_bom_revisions r ON r.bom_id = b.id WHERE b.finished_item_id = sales_order_lines.item_id AND b.base_uom_id = sales_order_lines.uom_id AND b.branch_id = ? AND b.is_active = 1 AND b.deleted_at IS NULL AND r.status = 'ACTIVE' AND EXISTS (SELECT 1 FROM production_bom_lines l WHERE l.bom_revision_id = r.id))";
    }

    public static function itemReadySql(): string
    {
        return "wms_items.id IS NOT NULL AND wms_items.deleted_at IS NULL AND COALESCE(wms_items.is_active, 0) = 1 AND COALESCE(wms_items.item_type, '') = 'GOODS' AND COALESCE(wms_items.is_stock_item, 0) = 1 AND COALESCE(wms_items.base_uom_id, 0) > 0 AND COALESCE(wms_items.base_uom_id, 0) = COALESCE(sales_order_lines.uom_id, -1)";
    }
}
