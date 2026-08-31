<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Allocation extends Model
{
    protected $table = 'finance_allocations';

    protected $fillable = [
        'debit_open_item_id', 'credit_open_item_id', 'allocation_date', 'amount', 'source_type', 'source_id',
        'idempotency_key', 'allocation_hash', 'created_by', 'reversed_by', 'reversed_at', 'reversal_date',
        'reversal_reason', 'reversal_key',
    ];

    protected function casts(): array
    {
        return [
            'allocation_date' => 'date',
            'amount' => 'decimal:2',
            'reversed_at' => 'datetime',
            'reversal_date' => 'date',
        ];
    }

    public function debitOpenItem(): BelongsTo
    {
        return $this->belongsTo(OpenItem::class, 'debit_open_item_id');
    }

    public function creditOpenItem(): BelongsTo
    {
        return $this->belongsTo(OpenItem::class, 'credit_open_item_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }

    public function taxRealization(): HasOne
    {
        return $this->hasOne(TaxRealization::class);
    }
}
