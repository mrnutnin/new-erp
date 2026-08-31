<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;

class Uom extends Model
{
    protected $table = 'wms_uoms';

    protected $fillable = ['code', 'name', 'decimal_places', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['decimal_places' => 'integer', 'is_active' => 'boolean'];
    }

    public function fromConversions()
    {
        return $this->hasMany(UomConversion::class, 'from_uom_id');
    }

    public function toConversions()
    {
        return $this->hasMany(UomConversion::class, 'to_uom_id');
    }
}
