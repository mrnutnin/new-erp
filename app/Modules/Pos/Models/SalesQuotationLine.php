<?php

namespace App\Modules\Pos\Models;

use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesQuotationLine extends Model
{
    protected $fillable = [
        'sales_quotation_id', 'source_rfq_line_id', 'line_number', 'item_id', 'uom_id', 'description',
        'quantity', 'unit_price', 'discount_amount', 'promotion_discount_amount', 'line_total', 'pricing_snapshot', 'item_snapshot', 'uom_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'promotion_discount_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'pricing_snapshot' => 'array',
            'item_snapshot' => 'array',
            'uom_snapshot' => 'array',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(SalesQuotation::class, 'sales_quotation_id');
    }

    public function sourceRfqLine(): BelongsTo
    {
        return $this->belongsTo(SalesRfqLine::class, 'source_rfq_line_id');
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
