<?php

namespace App\Modules\Pos\Models;

use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesReturnInventoryLink extends Model
{
    protected $table = 'pos_sales_return_inventory_links';

    protected $fillable = ['sales_return_line_id', 'source_stock_movement_id', 'reversal_stock_movement_id', 'source_cost_allocation_id', 'reversal_cost_allocation_id'];

    public function returnLine(): BelongsTo
    {
        return $this->belongsTo(SalesReturnLine::class, 'sales_return_line_id');
    }

    public function sourceMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'source_stock_movement_id');
    }

    public function reversalMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'reversal_stock_movement_id');
    }

    public function sourceAllocation(): BelongsTo
    {
        return $this->belongsTo(CostAllocation::class, 'source_cost_allocation_id');
    }

    public function reversalAllocation(): BelongsTo
    {
        return $this->belongsTo(CostAllocation::class, 'reversal_cost_allocation_id');
    }
}
