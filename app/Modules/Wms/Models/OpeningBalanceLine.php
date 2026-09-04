<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;

class OpeningBalanceLine extends Model
{
    protected $table = 'wms_opening_balance_lines';

    protected $fillable = ['batch_id', 'item_id', 'uom_id', 'quantity', 'unit_cost', 'total_value', 'stock_movement_id', 'cost_layer_id'];

    protected function casts(): array
    {
        return ['batch_id' => 'integer', 'item_id' => 'integer', 'uom_id' => 'integer', 'quantity' => 'decimal:8', 'unit_cost' => 'decimal:8', 'total_value' => 'decimal:8', 'stock_movement_id' => 'integer', 'cost_layer_id' => 'integer'];
    }

    public function batch() { return $this->belongsTo(OpeningBalanceBatch::class, 'batch_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function uom() { return $this->belongsTo(Uom::class); }
    public function movement() { return $this->belongsTo(StockMovement::class, 'stock_movement_id'); }
    public function costLayer() { return $this->belongsTo(StockCostLayer::class, 'cost_layer_id'); }
}
