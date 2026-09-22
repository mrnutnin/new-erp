<?php

namespace App\Modules\Production\Models;

use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;

final class ProductionOrderMaterial extends Model
{
    protected $table = 'production_order_materials';

    protected $fillable = ['production_order_id', 'line_number', 'source_bom_line_id', 'item_id', 'uom_id', 'required_quantity', 'override_reason'];

    protected function casts(): array
    {
        return ['production_order_id' => 'integer', 'line_number' => 'integer', 'source_bom_line_id' => 'integer', 'item_id' => 'integer', 'uom_id' => 'integer', 'required_quantity' => 'decimal:8'];
    }

    public function order() { return $this->belongsTo(ProductionOrder::class, 'production_order_id'); }
    public function item() { return $this->belongsTo(Item::class); }
    public function uom() { return $this->belongsTo(Uom::class); }
    public function bomLine() { return $this->belongsTo(BomLine::class, 'source_bom_line_id'); }
}
