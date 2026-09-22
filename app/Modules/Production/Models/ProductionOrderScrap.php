<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;

final class ProductionOrderScrap extends Model
{
    protected $table = 'production_order_scraps';

    protected $fillable = ['production_order_id', 'source_material_line_id', 'scrap_type', 'scrap_item_id', 'uom_id', 'quantity', 'recovery_unit_value', 'recovery_total_value', 'reason', 'status', 'reported_by', 'reported_at'];

    protected function casts(): array
    {
        return ['production_order_id' => 'integer', 'source_material_line_id' => 'integer', 'scrap_item_id' => 'integer', 'uom_id' => 'integer', 'quantity' => 'decimal:8', 'recovery_unit_value' => 'decimal:8', 'recovery_total_value' => 'decimal:8', 'reported_by' => 'integer', 'reported_at' => 'datetime'];
    }

    public function order() { return $this->belongsTo(ProductionOrder::class, 'production_order_id'); }
    public function sourceMaterial() { return $this->belongsTo(ProductionOrderMaterial::class, 'source_material_line_id'); }
    public function scrapItem() { return $this->belongsTo(Item::class, 'scrap_item_id'); }
    public function uom() { return $this->belongsTo(Uom::class); }
    public function reporter() { return $this->belongsTo(User::class, 'reported_by'); }
}
