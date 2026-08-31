<?php

namespace App\Modules\Pos\Models;

use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SalesReturnLine extends Model
{
    protected $table = 'pos_sales_return_lines';

    protected $fillable = ['sales_return_id', 'physical_sale_line_id', 'line_number', 'item_id', 'uom_id', 'stock_uom_id', 'quantity', 'stock_quantity', 'uom_factor', 'unit_price', 'line_total', 'item_snapshot', 'conversion_snapshot'];

    protected function casts(): array
    {
        return ['line_number' => 'integer', 'stock_uom_id' => 'integer', 'quantity' => 'decimal:8', 'stock_quantity' => 'decimal:8', 'uom_factor' => 'decimal:8', 'unit_price' => 'decimal:4', 'line_total' => 'decimal:2', 'item_snapshot' => 'array', 'conversion_snapshot' => 'array'];
    }

    public function returnDocument(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'sales_return_id');
    }

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(PhysicalSaleLine::class, 'physical_sale_line_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    public function stockUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'stock_uom_id');
    }

    public function inventoryLinks(): HasMany
    {
        return $this->hasMany(SalesReturnInventoryLink::class, 'sales_return_line_id');
    }
}
