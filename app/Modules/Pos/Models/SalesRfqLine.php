<?php

namespace App\Modules\Pos\Models;

use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesRfqLine extends Model
{
    protected $fillable = [
        'sales_rfq_id', 'line_number', 'item_id', 'uom_id', 'description',
        'quantity', 'proposed_unit_price', 'proposed_discount_amount', 'estimated_unit_cost', 'estimated_cost_amount', 'estimated_margin_amount', 'estimated_margin_percent', 'item_snapshot', 'uom_snapshot',
        'line_total', 'pricing_snapshot', 'promotion_discount_amount',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'proposed_unit_price' => 'decimal:4', 'proposed_discount_amount' => 'decimal:2', 'line_total' => 'decimal:2', 'pricing_snapshot' => 'array', 'promotion_discount_amount' => 'decimal:2', 'estimated_unit_cost' => 'decimal:4', 'estimated_cost_amount' => 'decimal:2', 'estimated_margin_amount' => 'decimal:2', 'estimated_margin_percent' => 'decimal:4', 'item_snapshot' => 'array', 'uom_snapshot' => 'array'];
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(SalesRfq::class, 'sales_rfq_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }
}
