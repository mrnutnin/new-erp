<?php

namespace App\Modules\Accounting\Models;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JournalEntry extends Model
{
    protected $fillable = [
        'journal_book_id', 'fiscal_period_id', 'branch_id', 'warehouse_id', 'sequence_number',
        'entry_number', 'entry_date', 'document_date', 'source_type', 'source_event', 'source_id', 'source_reference',
        'idempotency_key', 'posting_hash', 'posting_metadata',
        'description', 'currency_code', 'exchange_rate', 'status', 'reversal_of_id',
        'validated_by', 'validated_at', 'validation_reason', 'posted_by', 'posted_at',
        'posting_reason', 'reversed_by', 'reversed_at', 'reversal_reason', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'document_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'posting_metadata' => 'array',
            'validated_at' => 'datetime',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function book(): BelongsTo
    {
        return $this->belongsTo(JournalBook::class, 'journal_book_id');
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class)->orderBy('line_number');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
