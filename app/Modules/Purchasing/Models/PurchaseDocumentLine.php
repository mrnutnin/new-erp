<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Purchasing\Models\GoodsReceiptLine;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseDocumentLine extends Model
{
    protected $table = 'purchase_document_lines';
    protected $fillable = ['line_number','description','item_id','uom_id','purchase_order_line_id','account_id','tax_code_id','tax_rate','tax_base','quantity','unit_price','discount_amount','net_amount','tax_amount','gross_amount','withholding_tax_code_id','withholding_rate','withholding_base','withholding_amount'];
    protected function casts(): array { return ['item_id'=>'integer','uom_id'=>'integer','purchase_order_line_id'=>'integer','tax_code_id'=>'integer','tax_rate'=>'decimal:4','tax_base'=>'decimal:2','quantity'=>'decimal:4','unit_price'=>'decimal:4','discount_amount'=>'decimal:2','net_amount'=>'decimal:2','tax_amount'=>'decimal:2','gross_amount'=>'decimal:2','withholding_tax_code_id'=>'integer','withholding_rate'=>'decimal:4','withholding_base'=>'decimal:2','withholding_amount'=>'decimal:2']; }
    public function document(): BelongsTo { return $this->belongsTo(PurchaseDocument::class, 'purchase_document_id'); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
    public function uom(): BelongsTo { return $this->belongsTo(Uom::class); }
    public function purchaseOrderLine(): BelongsTo { return $this->belongsTo(PurchaseOrderLine::class); }
    public function receiptAllocations(): HasMany { return $this->hasMany(PurchaseDocumentReceiptAllocation::class); }
    public function taxCode(): BelongsTo { return $this->belongsTo(TaxCode::class); }
    public function withholdingTaxCode(): BelongsTo { return $this->belongsTo(TaxCode::class, 'withholding_tax_code_id'); }
    public function purchaseReturnLines(): HasMany { return $this->hasMany(PurchaseReturnLine::class); }
}
