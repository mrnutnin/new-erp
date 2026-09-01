<?php

namespace App\Modules\Asset\Models;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetDisposal extends Model
{
    use SoftDeletes;

    protected $fillable = ['document_number', 'branch_id', 'disposal_type', 'disposal_date', 'status', 'proceeds', 'proceeds_reference', 'count_reference', 'investigation_reference', 'override_reason', 'reason', 'journal_entry_id', 'reversal_of_id', 'reversal_journal_entry_id', 'reversed_by', 'reversed_at', 'reversal_date', 'reversal_reason', 'created_by', 'submitted_by', 'approved_by', 'posted_by', 'cancelled_by', 'submitted_at', 'approved_at', 'posted_at', 'cancelled_at', 'cancellation_reason'];

    protected function casts(): array
    {
        return ['disposal_date' => 'date', 'reversal_date' => 'date', 'proceeds' => 'decimal:2', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'posted_at' => 'datetime', 'cancelled_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function lines(): HasMany { return $this->hasMany(AssetDisposalLine::class); }
    public function journalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class); }
    public function reversalJournalEntry(): BelongsTo { return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id'); }
    public function reversalOf(): BelongsTo { return $this->belongsTo(self::class, 'reversal_of_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
