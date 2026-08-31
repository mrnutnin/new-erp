<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceiptLine extends Model
{
    protected $fillable = ['goods_receipt_id', 'purchase_order_line_id', 'item_id', 'purchase_uom_id', 'stock_uom_id', 'purchase_quantity', 'factor', 'stock_quantity', 'total_cost', 'stock_unit_cost', 'rounding_delta', 'conversion_snapshot'];

    protected function casts(): array
    {
        return ['purchase_quantity' => 'decimal:8', 'factor' => 'decimal:8', 'stock_quantity' => 'decimal:8', 'total_cost' => 'decimal:8', 'stock_unit_cost' => 'decimal:8', 'rounding_delta' => 'decimal:8', 'conversion_snapshot' => 'array'];
    }

    public function goodsReceipt()
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderLine()
    {
        return $this->belongsTo(PurchaseOrderLine::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function purchaseUom()
    {
        return $this->belongsTo(Uom::class, 'purchase_uom_id');
    }

    public function stockUom()
    {
        return $this->belongsTo(Uom::class, 'stock_uom_id');
    }

    public function purchaseDocumentAllocations(): HasMany
    {
        return $this->hasMany(PurchaseDocumentReceiptAllocation::class);
    }
}
