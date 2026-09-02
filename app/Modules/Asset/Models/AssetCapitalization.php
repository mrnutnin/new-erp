<?php

namespace App\Modules\Asset\Models;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetCapitalization extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'document_number', 'transaction_type', 'branch_id', 'document_date', 'source_type', 'source_id', 'is_manual_exception', 'manual_exception_reason', 'status', 'description',
        'journal_entry_id', 'reversal_journal_entry_id', 'reversal_of_id', 'submitted_by', 'submitted_at',
        'approved_by', 'approved_at', 'posted_by', 'posted_at', 'reversed_by', 'reversed_at', 'reversal_date',
        'reversal_reason', 'voided_by', 'voided_at', 'void_reason', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'document_date' => 'date', 'is_manual_exception' => 'boolean', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime',
            'reversed_at' => 'datetime', 'reversal_date' => 'date',
            'voided_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AssetCapitalizationLine::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalJournalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal(): HasMany
    {
        return $this->hasMany(self::class, 'reversal_of_id');
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
}
