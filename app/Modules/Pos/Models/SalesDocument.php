<?php

namespace App\Modules\Pos\Models;

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

class SalesDocument extends Model
{
    use HasDocumentBranch;

    protected $fillable = ['warehouse_id', 'branch_id', 'document_type', 'document_number', 'source_invoice_id', 'party_id', 'payment_term_id', 'journal_entry_id', 'document_date', 'posting_date', 'due_date', 'price_includes_vat', 'tax_decimal_places', 'party_code', 'party_name', 'party_tax_id', 'party_branch_code', 'party_address', 'subtotal', 'discount_amount', 'tax_base', 'tax_amount', 'withholding_tax_code_id', 'withholding_rate', 'withholding_base', 'withholding_amount', 'total_amount', 'status', 'approved_by', 'approved_at', 'approval_reason', 'discount_approval_snapshot', 'posted_by', 'posted_at', 'voided_by', 'voided_at', 'void_reason', 'description', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['document_date' => 'date', 'posting_date' => 'date', 'due_date' => 'date', 'price_includes_vat' => 'boolean', 'tax_decimal_places' => 'integer', 'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2', 'tax_base' => 'decimal:2', 'tax_amount' => 'decimal:2', 'withholding_tax_code_id' => 'integer', 'withholding_rate' => 'decimal:4', 'withholding_base' => 'decimal:2', 'withholding_amount' => 'decimal:2', 'total_amount' => 'decimal:2', 'discount_approval_snapshot' => 'array', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(SalesDocumentLine::class)->orderBy('line_number');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function withholdingTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'withholding_tax_code_id');
    }

    public function sourceInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_invoice_id');
    }

    public function billingNoteLines(): HasMany
    {
        return $this->hasMany(BillingNoteLine::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
