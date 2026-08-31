<?php

namespace App\Modules\Finance\Models;

use App\Models\Party;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Settlement extends Model
{
    use SoftDeletes;

    protected $table = 'finance_settlements';

    protected $fillable = ['document_type', 'document_number', 'document_date', 'settlement_date', 'party_type', 'party_id', 'bank_account_id', 'payment_term_id', 'journal_entry_id', 'reversal_journal_entry_id', 'gross_amount', 'tax_amount', 'withholding_amount', 'net_amount', 'status', 'approved_by', 'approved_at', 'approval_reason', 'posted_by', 'posted_at', 'reversed_by', 'reversed_at', 'reversal_date', 'reversal_reason', 'voided_by', 'voided_at', 'void_reason', 'description', 'created_by'];

    protected function casts(): array
    {
        return ['party_id' => 'integer', 'document_date' => 'date', 'settlement_date' => 'date', 'reversal_date' => 'date', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'voided_at' => 'datetime', 'gross_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'withholding_amount' => 'decimal:2', 'net_amount' => 'decimal:2'];
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function paymentTerm()
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function allocationIntents(): HasMany
    {
        return $this->hasMany(SettlementAllocationIntent::class)->orderBy('line_number');
    }

    public function tenders(): HasMany
    {
        return $this->hasMany(SettlementTender::class)->orderBy('line_number');
    }
}
