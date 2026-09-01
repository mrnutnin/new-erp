<?php

namespace App\Modules\Asset\Models;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AssetValueEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'asset_id', 'branch_id', 'event_date', 'event_type', 'cost_delta', 'depreciation_delta', 'impairment_delta',
        'source_type', 'source_id', 'source_line_id', 'journal_entry_id', 'reversal_of_event_id', 'idempotency_key', 'created_by',
    ];

    protected function casts(): array
    {
        return ['event_date' => 'date', 'cost_delta' => 'decimal:2', 'depreciation_delta' => 'decimal:2', 'impairment_delta' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Asset value events are append-only.'));
        static::deleting(fn () => throw new LogicException('Asset value events are append-only.'));
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_event_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
