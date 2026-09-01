<?php

namespace App\Modules\Asset\Models;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetDepreciationRun extends Model
{
    protected $fillable = [
        'document_number', 'branch_id', 'fiscal_period_id', 'book_type', 'run_through_date', 'status', 'asset_count',
        'total_depreciation', 'total_catch_up_adjustment', 'calculation_hash', 'progress_percent', 'error_message',
        'journal_entry_id', 'reversal_journal_entry_id', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at',
        'posted_by', 'posted_at', 'reversed_by', 'reversed_at', 'reversal_date', 'reversal_reason', 'created_by', 'updated_by',
        'cancelled_by', 'cancelled_at', 'cancellation_reason',
    ];

    protected function casts(): array
    {
        return [
            'run_through_date' => 'date', 'total_depreciation' => 'decimal:2', 'total_catch_up_adjustment' => 'decimal:2',
            'progress_percent' => 'decimal:2', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime',
            'reversed_at' => 'datetime', 'reversal_date' => 'date',
            'cancelled_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AssetDepreciationLine::class);
    }

    public function exceptions(): HasMany
    {
        return $this->hasMany(AssetDepreciationRunException::class);
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

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
