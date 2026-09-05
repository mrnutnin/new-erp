<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeAdvance extends Model
{
    use SoftDeletes;
    protected $table = 'finance_employee_advances';

    protected $fillable = [
        'branch_id', 'warehouse_id', 'employee_user_id', 'bank_account_id', 'document_number', 'document_date', 'due_date',
        'amount', 'purpose', 'status', 'journal_entry_id', 'reversal_journal_entry_id', 'created_by', 'submitted_by',
        'submitted_at', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'reversed_by', 'reversed_at',
        'reversal_reason', 'idempotency_key', 'reversal_key',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date', 'due_date' => 'date', 'amount' => 'decimal:2',
            'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime',
        ];
    }

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function employee(): BelongsTo { return $this->belongsTo(User::class, 'employee_user_id'); }
    public function bankAccount(): BelongsTo { return $this->belongsTo(BankAccount::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function reversalJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
