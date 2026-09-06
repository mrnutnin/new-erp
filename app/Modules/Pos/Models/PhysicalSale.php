<?php

namespace App\Modules\Pos\Models;

use App\Models\Concerns\HasDocumentBranch;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Finance\Models\AdvanceDepositApplication;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PhysicalSale extends Model
{
    use HasDocumentBranch;

    protected $table = 'pos_physical_sales';

    protected $fillable = [
        'warehouse_id', 'branch_id', 'document_type', 'document_number', 'source_type', 'source_id', 'party_id',
        'party_code', 'party_name', 'party_tax_id', 'party_branch_code', 'party_address',
        'document_date', 'tax_treatment', 'prices_include_vat', 'due_date', 'posting_date', 'subtotal', 'discount_amount', 'promotion_snapshot', 'promotion_discount_amount', 'tax_base', 'tax_amount', 'withholding_tax_code_id', 'withholding_rate', 'withholding_base', 'withholding_amount', 'total_amount',
        'status', 'journal_entry_id', 'cogs_journal_entry_id', 'cancellation_return_id', 'reversal_status', 'reversal_revision', 'reversal_key', 'posted_by', 'posted_at', 'voided_by', 'voided_at', 'void_reason',
        'description', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'source_id' => 'integer', 'party_id' => 'integer', 'journal_entry_id' => 'integer', 'cogs_journal_entry_id' => 'integer', 'cancellation_return_id' => 'integer', 'reversal_revision' => 'integer', 'prices_include_vat' => 'boolean', 'document_date' => 'date', 'due_date' => 'date', 'posting_date' => 'date',
            'subtotal' => 'decimal:2', 'discount_amount' => 'decimal:2', 'promotion_snapshot' => 'array', 'promotion_discount_amount' => 'decimal:2', 'tax_base' => 'decimal:2', 'tax_amount' => 'decimal:2', 'withholding_tax_code_id' => 'integer', 'withholding_rate' => 'decimal:4', 'withholding_base' => 'decimal:2', 'withholding_amount' => 'decimal:2', 'total_amount' => 'decimal:2',
            'posted_at' => 'datetime', 'voided_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PhysicalSaleLine::class, 'physical_sale_id')->orderBy('line_number');
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(PhysicalSaleTender::class, 'physical_sale_id')->orderBy('line_number');
    }

    public function advanceDepositApplications(): HasMany
    {
        return $this->hasMany(AdvanceDepositApplication::class, 'physical_sale_id');
    }

    public function billingNoteLines(): HasMany
    {
        return $this->hasMany(BillingNoteLine::class, 'physical_sale_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function cogsJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'cogs_journal_entry_id');
    }

    public function cancellationReturn(): BelongsTo
    {
        return $this->belongsTo(SalesReturn::class, 'cancellation_return_id');
    }

    public function withholdingTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'withholding_tax_code_id');
    }
}
