<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Purchasing\Models\GoodsReceiptLine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseDocumentReceiptAllocation extends Model
{
    protected $table = 'purchase_document_receipt_allocations';
    protected $fillable = ['purchase_document_line_id','goods_receipt_line_id','allocated_quantity','allocated_amount','idempotency_key'];
    protected function casts(): array { return ['purchase_document_line_id'=>'integer','goods_receipt_line_id'=>'integer','allocated_quantity'=>'decimal:8','allocated_amount'=>'decimal:8']; }
    public function purchaseDocumentLine(): BelongsTo { return $this->belongsTo(PurchaseDocumentLine::class); }
    public function goodsReceiptLine(): BelongsTo { return $this->belongsTo(GoodsReceiptLine::class); }
}
