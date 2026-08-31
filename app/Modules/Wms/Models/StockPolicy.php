<?php

namespace App\Modules\Wms\Models;

use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StockPolicy extends Model
{
    use SoftDeletes;

    protected $table = 'wms_stock_policies';

    protected $fillable = ['warehouse_id', 'item_id', 'min_quantity', 'max_quantity', 'reorder_quantity', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['warehouse_id' => 'integer', 'item_id' => 'integer', 'min_quantity' => 'decimal:8', 'max_quantity' => 'decimal:8', 'reorder_quantity' => 'decimal:8', 'is_active' => 'boolean'];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
