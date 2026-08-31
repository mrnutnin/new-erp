<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;

class UomConversion extends Model
{
    protected $table = 'wms_uom_conversions';

    protected $fillable = ['from_uom_id', 'to_uom_id', 'factor', 'effective_from', 'effective_to', 'created_by'];

    protected function casts(): array
    {
        return ['from_uom_id' => 'integer', 'to_uom_id' => 'integer', 'factor' => 'decimal:8', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }

    public function fromUom()
    {
        return $this->belongsTo(Uom::class, 'from_uom_id');
    }

    public function toUom()
    {
        return $this->belongsTo(Uom::class, 'to_uom_id');
    }
}
