<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;

class StockCostLayer extends Model
{
    protected $table = 'wms_stock_cost_layers';

    protected $fillable = ['warehouse_id', 'item_id', 'uom_id', 'source_movement_id', 'parent_layer_id', 'lineage_key', 'original_quantity', 'remaining_quantity', 'unit_cost', 'method', 'cost_status', 'recost_request_id', 'resolved_by_movement_id', 'cost_delta', 'resolved_at', 'business_date'];

    protected function casts(): array
    {
        return ['warehouse_id' => 'integer', 'item_id' => 'integer', 'uom_id' => 'integer', 'source_movement_id' => 'integer', 'parent_layer_id' => 'integer', 'recost_request_id' => 'integer', 'resolved_by_movement_id' => 'integer', 'original_quantity' => 'decimal:8', 'remaining_quantity' => 'decimal:8', 'unit_cost' => 'decimal:8', 'cost_delta' => 'decimal:8', 'resolved_at' => 'datetime', 'business_date' => 'date:Y-m-d'];
    }

    public function parentLayer()
    {
        return $this->belongsTo(self::class, 'parent_layer_id');
    }
}
