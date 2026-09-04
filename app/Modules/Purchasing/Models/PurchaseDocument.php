<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasDocumentBranch;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Finance\Models\PaymentTerm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseDocument extends Model
{
    use HasDocumentBranch;
    protected $table = 'purchase_documents';
    protected $fillable = ['warehouse_id','branch_id','document_type','credit_note_mode','original_document_id','document_number','document_date','posting_date','supplier_id','supplier_code','supplier_name','supplier_tax_id','supplier_branch_code','supplier_address','payment_term_id','due_date','tax_treatment','prices_include_vat','tax_decimal_places','subtotal','tax_amount','withholding_tax_code_id','withholding_rate','withholding_base','withholding_amount','gross_amount','rounding_amount','status','reversal_status','journal_entry_id','reversal_journal_entry_id','description','approved_by','approved_at','approval_reason','posted_by','posted_at','voided_by','voided_at','void_reason','reversed_by','reversed_at','reversal_reason','reversal_revision','inventory_reversal_movement_id','inventory_reversal_allocation_id','created_by','updated_by'];
    protected function casts(): array { return ['document_date'=>'date','posting_date'=>'date','due_date'=>'date','prices_include_vat'=>'boolean','tax_decimal_places'=>'integer','subtotal'=>'decimal:2','tax_amount'=>'decimal:2','withholding_tax_code_id'=>'integer','withholding_rate'=>'decimal:4','withholding_base'=>'decimal:2','withholding_amount'=>'decimal:2','gross_amount'=>'decimal:2','rounding_amount'=>'decimal:2','reversal_revision'=>'integer','inventory_reversal_movement_id'=>'integer','inventory_reversal_allocation_id'=>'integer','approved_at'=>'datetime','posted_at'=>'datetime','voided_at'=>'datetime','reversed_at'=>'datetime']; }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Party::class, 'supplier_id'); }
    public function paymentTerm(): BelongsTo { return $this->belongsTo(PaymentTerm::class); }
    public function withholdingTaxCode(): BelongsTo { return $this->belongsTo(TaxCode::class, 'withholding_tax_code_id'); }
    public function originalDocument(): BelongsTo { return $this->belongsTo(self::class, 'original_document_id'); }
    public function lines(): HasMany { return $this->hasMany(PurchaseDocumentLine::class)->orderBy('line_number'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function varianceApprovals(): HasMany { return $this->hasMany(PurchaseVarianceApproval::class, 'purchase_document_id')->latest('revision'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
