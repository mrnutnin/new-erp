<?php

namespace App\Modules\Production\Models;

use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;

final class BomLineSubstitute extends Model
{
    protected $table = 'production_bom_line_substitutes';
    protected $fillable = ['bom_line_id', 'substitute_item_id', 'uom_id', 'quantity_factor', 'priority', 'notes'];

    protected function casts(): array
    {
        return ['quantity_factor' => 'decimal:8', 'priority' => 'integer'];
    }

    public function bomLine() { return $this->belongsTo(BomLine::class, 'bom_line_id'); }
    public function substituteItem() { return $this->belongsTo(Item::class, 'substitute_item_id'); }
    public function uom() { return $this->belongsTo(Uom::class); }
}
