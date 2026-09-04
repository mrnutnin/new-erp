<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasDocumentBranch;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseReturn extends Model
{
    use HasDocumentBranch;

    protected $table = 'purchase_returns';
    protected $fillable = ['warehouse_id', 'branch_id', 'supplier_id', 'purchase_document_id', 'goods_receipt_id', 'credit_note_id', 'return_number', 'idempotency_key', 'return_date', 'reason', 'supplier_code', 'supplier_name', 'supplier_tax_id', 'supplier_branch_code', 'supplier_address', 'tax_treatment', 'prices_include_vat', 'tax_decimal_places', 'subtotal', 'tax_amount', 'gross_amount', 'status', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'voided_by', 'voided_at', 'void_reason', 'created_by', 'updated_by'];
    protected function casts(): array { return ['return_date' => 'date', 'prices_include_vat' => 'boolean', 'tax_decimal_places' => 'integer', 'subtotal' => 'decimal:2', 'tax_amount' => 'decimal:2', 'gross_amount' => 'decimal:2', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'voided_at' => 'datetime']; }

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Party::class, 'supplier_id'); }
    public function purchaseDocument(): BelongsTo { return $this->belongsTo(PurchaseDocument::class); }
    public function goodsReceipt(): BelongsTo { return $this->belongsTo(GoodsReceipt::class); }
    public function creditNote(): BelongsTo { return $this->belongsTo(PurchaseDocument::class, 'credit_note_id'); }
    public function lines(): HasMany { return $this->hasMany(PurchaseReturnLine::class)->orderBy('id'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function postedBy(): BelongsTo { return $this->belongsTo(User::class, 'posted_by'); }
    public function voidedBy(): BelongsTo { return $this->belongsTo(User::class, 'voided_by'); }
}
