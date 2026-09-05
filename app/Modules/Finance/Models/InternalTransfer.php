<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class InternalTransfer extends Model
{
    use SoftDeletes;

    protected $table = 'finance_internal_transfers';

    protected $fillable = ['warehouse_id', 'document_number', 'document_date', 'source_bank_account_id', 'destination_bank_account_id', 'amount', 'description', 'status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'journal_entry_id', 'idempotency_key', 'posted_by', 'posted_at', 'reversal_journal_entry_id', 'reversal_key', 'reversed_by', 'reversed_at', 'reversal_reason', 'voided_by', 'voided_at', 'void_reason', 'created_by'];

    protected function casts(): array
    {
        return ['document_date' => 'date', 'amount' => 'decimal:2', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function sourceBankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'source_bank_account_id'); }
    public function destinationBankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class, 'destination_bank_account_id'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function reversalJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
