<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Purchasing\Models\GoodsReceipt;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandedCostReceipt extends Model
{
    protected $table = 'purchasing_landed_cost_receipts';

    protected $fillable = ['landed_cost_id', 'goods_receipt_id', 'selected_value', 'allocated_amount'];

    protected function casts(): array
    {
        return ['selected_value' => 'decimal:8', 'allocated_amount' => 'decimal:8'];
    }

    public function landedCost(): BelongsTo
    {
        return $this->belongsTo(LandedCost::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }
}
