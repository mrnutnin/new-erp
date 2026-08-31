<?php

namespace App\Modules\Pos\Models;

use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;

class SalesIntakeLine extends Model
{
    protected $fillable = ['sales_intake_id', 'line_number', 'item_id', 'uom_id', 'description', 'quantity', 'standard_unit_price', 'requested_unit_price', 'discount_amount', 'promotion_discount_amount', 'tax_code_id', 'tax_rate', 'tax_base', 'tax_amount', 'line_total', 'pricing_snapshot', 'item_snapshot', 'uom_snapshot'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'standard_unit_price' => 'decimal:4', 'requested_unit_price' => 'decimal:4', 'discount_amount' => 'decimal:4', 'promotion_discount_amount' => 'decimal:4', 'tax_rate' => 'decimal:4', 'tax_base' => 'decimal:4', 'tax_amount' => 'decimal:4', 'line_total' => 'decimal:4', 'pricing_snapshot' => 'array', 'item_snapshot' => 'array', 'uom_snapshot' => 'array'];
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function uom()
    {
        return $this->belongsTo(Uom::class);
    }
}
