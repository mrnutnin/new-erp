<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReturnLine extends Model
{
    protected $table = 'purchase_return_lines';
    protected $fillable = ['purchase_return_id', 'goods_receipt_line_id', 'purchase_document_line_id', 'item_id', 'purchase_uom_id', 'stock_uom_id', 'purchase_quantity', 'stock_quantity', 'factor', 'unit_cost', 'total_cost', 'net_amount', 'tax_amount', 'gross_amount', 'reason', 'source_snapshot'];
    protected function casts(): array { return ['purchase_quantity' => 'decimal:8', 'stock_quantity' => 'decimal:8', 'factor' => 'decimal:8', 'unit_cost' => 'decimal:8', 'total_cost' => 'decimal:8', 'net_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'gross_amount' => 'decimal:2', 'source_snapshot' => 'array']; }

    public function purchaseReturn(): BelongsTo { return $this->belongsTo(PurchaseReturn::class); }
    public function goodsReceiptLine(): BelongsTo { return $this->belongsTo(GoodsReceiptLine::class); }
    public function purchaseDocumentLine(): BelongsTo { return $this->belongsTo(PurchaseDocumentLine::class); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
    public function purchaseUom(): BelongsTo { return $this->belongsTo(Uom::class, 'purchase_uom_id'); }
    public function stockUom(): BelongsTo { return $this->belongsTo(Uom::class, 'stock_uom_id'); }
}
