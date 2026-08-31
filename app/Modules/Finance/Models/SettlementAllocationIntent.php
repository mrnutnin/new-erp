<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementAllocationIntent extends Model
{
    protected $table = 'finance_settlement_allocation_intents';

    protected $fillable = ['settlement_id', 'open_item_id', 'line_number', 'amount', 'allocation_id'];

    protected function casts(): array
    {
        return [
            'line_number' => 'integer',
            'amount' => 'decimal:2',
            'allocation_id' => 'integer',
        ];
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }

    public function openItem(): BelongsTo
    {
        return $this->belongsTo(OpenItem::class);
    }
}
