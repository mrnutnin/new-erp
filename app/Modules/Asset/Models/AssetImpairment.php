<?php

namespace App\Modules\Asset\Models;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetImpairment extends Model
{
    use SoftDeletes;

    protected $fillable = ['document_number', 'asset_id', 'branch_id', 'assessment_date', 'status', 'carrying_amount', 'recoverable_amount', 'impairment_amount', 'journal_entry_id', 'reversal_of_id', 'reversal_journal_entry_id', 'reversal_reason', 'evidence_reference', 'reason', 'created_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'posted_by', 'posted_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason'];

    protected function casts(): array
    {
        return ['assessment_date' => 'date', 'carrying_amount' => 'decimal:2', 'recoverable_amount' => 'decimal:2', 'impairment_amount' => 'decimal:2', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }
}
