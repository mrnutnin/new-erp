<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandedCostAllocation extends Model
{
    protected $table = 'purchasing_landed_cost_allocations';

    protected $fillable = [
        'landed_cost_id', 'landed_cost_line_id', 'goods_receipt_line_id', 'item_id', 'uom_id',
        'basis_amount', 'allocation_ratio', 'allocated_amount', 'wms_cost_allocation_id', 'revision', 'status', 'idempotency_key', 'metadata',
    ];

    protected function casts(): array
    {
        return ['basis_amount' => 'decimal:8', 'allocation_ratio' => 'decimal:12', 'allocated_amount' => 'decimal:8', 'metadata' => 'array'];
    }

    public function landedCost(): BelongsTo { return $this->belongsTo(LandedCost::class); }
    public function line(): BelongsTo { return $this->belongsTo(LandedCostLine::class, 'landed_cost_line_id'); }
    public function goodsReceiptLine(): BelongsTo { return $this->belongsTo(GoodsReceiptLine::class); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
    public function uom(): BelongsTo { return $this->belongsTo(Uom::class); }
    public function wmsCostAllocation(): BelongsTo { return $this->belongsTo(CostAllocation::class, 'wms_cost_allocation_id'); }
}
