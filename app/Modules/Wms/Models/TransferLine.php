<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;

class TransferLine extends Model
{
    protected $table = 'wms_transfer_lines';

    protected $fillable = ['transfer_id', 'item_id', 'uom_id', 'line_number', 'planned_quantity', 'planned_base_quantity'];

    protected function casts(): array
    {
        return ['transfer_id' => 'integer', 'item_id' => 'integer', 'uom_id' => 'integer', 'line_number' => 'integer', 'planned_quantity' => 'decimal:8', 'planned_base_quantity' => 'decimal:8'];
    }

    public function transfer()
    {
        return $this->belongsTo(Transfer::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function uom()
    {
        return $this->belongsTo(Uom::class);
    }

    public function events()
    {
        return $this->hasMany(TransferEvent::class);
    }
}
