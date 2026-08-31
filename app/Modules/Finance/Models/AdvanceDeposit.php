<?php

namespace App\Modules\Finance\Models;

use App\Models\Concerns\HasDocumentBranch;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\TaxCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdvanceDeposit extends Model
{
    use HasDocumentBranch;

    protected $table = 'finance_advance_deposits';

    protected $fillable = [
        'warehouse_id', 'branch_id', 'party_id', 'party_type', 'direction', 'instrument_type', 'source_settlement_id',
        'document_number', 'document_date', 'receipt_date', 'posting_date', 'currency_code', 'tax_treatment', 'prices_include_vat', 'is_tax_point', 'tax_code_id', 'tax_rate', 'tax_base', 'tax_amount', 'tax_point_date', 'withholding_tax_code_id', 'withholding_rate', 'withholding_base', 'withholding_amount', 'withholding_certificate_reference', 'net_amount', 'original_amount', 'applied_amount', 'balance_amount', 'status', 'journal_entry_id', 'reversal_journal_entry_id', 'refund_bank_account_id',
        'idempotency_key', 'reversal_of_id', 'created_by', 'posted_by', 'posted_at', 'reversed_by', 'reversed_at',
        'reversal_reason', 'reversal_key', 'description',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date', 'receipt_date' => 'date', 'posting_date' => 'date', 'tax_point_date' => 'date', 'prices_include_vat' => 'boolean', 'is_tax_point' => 'boolean', 'tax_code_id' => 'integer', 'withholding_tax_code_id' => 'integer', 'tax_rate' => 'decimal:4', 'tax_base' => 'decimal:2', 'tax_amount' => 'decimal:2', 'withholding_rate' => 'decimal:4', 'withholding_base' => 'decimal:2', 'withholding_amount' => 'decimal:2', 'net_amount' => 'decimal:2', 'original_amount' => 'decimal:2',
            'applied_amount' => 'decimal:2', 'balance_amount' => 'decimal:2', 'posted_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function sourceSettlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class, 'source_settlement_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function refundBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'refund_bank_account_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(AdvanceDepositApplication::class);
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(AdvanceDepositTender::class, 'advance_deposit_id')->orderBy('line_number');
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function withholdingTaxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class, 'withholding_tax_code_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
