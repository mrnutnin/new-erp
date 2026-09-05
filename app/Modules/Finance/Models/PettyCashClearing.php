<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PettyCashClearing extends Model
{
    use SoftDeletes;

    protected $table = 'finance_petty_cash_clearings';

    protected $fillable = ['document_number', 'petty_cash_fund_id', 'warehouse_id', 'clearing_date', 'expected_amount', 'actual_amount', 'variance_amount', 'reason', 'status', 'created_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'journal_entry_id', 'idempotency_key', 'reversal_journal_entry_id', 'reversal_key', 'posted_by', 'posted_at', 'reversed_by', 'reversed_at', 'reversal_reason', 'voided_by', 'voided_at', 'void_reason'];

    protected function casts(): array
    {
        return ['clearing_date' => 'date', 'expected_amount' => 'decimal:2', 'actual_amount' => 'decimal:2', 'variance_amount' => 'decimal:2', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function fund(): BelongsTo { return $this->belongsTo(PettyCashFund::class, 'petty_cash_fund_id'); }
    public function attachments(): HasMany { return $this->hasMany(PettyCashAttachment::class, 'subject_id')->where('subject_type', 'PETTY_CASH_CLEARING'); }
    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function submittedBy(): BelongsTo { return $this->belongsTo(User::class, 'submitted_by'); }
    public function approvedBy(): BelongsTo { return $this->belongsTo(User::class, 'approved_by'); }
    public function postedBy(): BelongsTo { return $this->belongsTo(User::class, 'posted_by'); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(\App\Modules\Accounting\Models\JournalEntry::class, 'journal_entry_id'); }
    public function reversalJournalEntry(): BelongsTo { return $this->belongsTo(\App\Modules\Accounting\Models\JournalEntry::class, 'reversal_journal_entry_id'); }
    public function voidedBy(): BelongsTo { return $this->belongsTo(User::class, 'voided_by'); }
}
