<?php

namespace App\Modules\Pos\Models;

use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhysicalSaleLine extends Model
{
    protected $table = 'pos_physical_sale_lines';

    protected $fillable = [
        'physical_sale_id', 'line_number', 'source_line_id', 'item_id', 'sale_uom_id', 'stock_uom_id',
        'quantity', 'uom_factor', 'stock_quantity', 'unit_price', 'discount_amount', 'promotion_discount_amount', 'tax_code_id', 'tax_rate', 'tax_base', 'tax_amount', 'line_total',
        'pricing_snapshot', 'item_snapshot', 'conversion_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer', 'source_line_id' => 'integer', 'item_id' => 'integer',
            'sale_uom_id' => 'integer', 'stock_uom_id' => 'integer', 'quantity' => 'decimal:8',
            'uom_factor' => 'decimal:8', 'stock_quantity' => 'decimal:8', 'unit_price' => 'decimal:4',
            'discount_amount' => 'decimal:2', 'promotion_discount_amount' => 'decimal:2', 'tax_code_id' => 'integer', 'tax_rate' => 'decimal:4', 'tax_base' => 'decimal:2', 'tax_amount' => 'decimal:2', 'line_total' => 'decimal:2', 'pricing_snapshot' => 'array', 'item_snapshot' => 'array',
            'conversion_snapshot' => 'array',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(PhysicalSale::class, 'physical_sale_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function saleUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'sale_uom_id');
    }

    public function stockUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'stock_uom_id');
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
