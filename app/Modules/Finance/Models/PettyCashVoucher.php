<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PettyCashVoucher extends Model
{
    use SoftDeletes;

    protected $table = 'finance_petty_cash_vouchers';

    protected $fillable = ['petty_cash_fund_id', 'warehouse_id', 'document_number', 'document_date', 'payee_type', 'payee_user_id', 'payee_party_id', 'payee_name', 'description', 'total_amount', 'tax_amount', 'withholding_amount', 'net_amount', 'status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'journal_entry_id', 'idempotency_key', 'posted_by', 'posted_at', 'reversal_journal_entry_id', 'reversal_key', 'reversed_by', 'reversed_at', 'reversal_reason', 'voided_by', 'voided_at', 'void_reason', 'created_by'];

    protected function casts(): array
    {
        return [
            'document_date' => 'date', 'total_amount' => 'decimal:2', 'tax_amount' => 'decimal:2', 'withholding_amount' => 'decimal:2', 'net_amount' => 'decimal:2', 'submitted_at' => 'datetime', 'approved_at' => 'datetime',
            'posted_at' => 'datetime', 'reversed_at' => 'datetime', 'voided_at' => 'datetime',
        ];
    }

    public function fund(): BelongsTo
    {
        return $this->belongsTo(PettyCashFund::class, 'petty_cash_fund_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PettyCashAttachment::class, 'subject_id')->where('subject_type', 'PETTY_CASH_VOUCHER');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PettyCashVoucherLine::class)->orderBy('line_number');
    }
}
