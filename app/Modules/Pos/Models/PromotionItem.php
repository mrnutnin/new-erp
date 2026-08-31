<?php

namespace App\Modules\Pos\Models;

use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PromotionItem extends Model
{
    use SoftDeletes;

    protected $table = 'pos_promotion_items';

    protected $fillable = [
        'promotion_id', 'item_id', 'uom_id', 'minimum_quantity', 'unit_price',
        'base_unit_price', 'discount_percent', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'minimum_quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'base_unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:4',
            'is_active' => 'boolean',
        ];
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
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
