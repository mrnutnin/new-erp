<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;

class StockBalance extends Model
{
    protected $table = 'wms_stock_balances';

    protected $fillable = ['warehouse_id', 'item_id', 'uom_id', 'on_hand', 'reserved', 'available', 'inventory_value', 'average_unit_cost'];

    protected function casts(): array
    {
        return ['warehouse_id' => 'integer', 'item_id' => 'integer', 'uom_id' => 'integer', 'on_hand' => 'decimal:8', 'reserved' => 'decimal:8', 'available' => 'decimal:8', 'inventory_value' => 'decimal:8', 'average_unit_cost' => 'decimal:8'];
    }
}
