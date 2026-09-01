<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetOpeningBalanceLine extends Model
{
    protected $fillable = [
        'asset_opening_balance_batch_id', 'row_key', 'source_reference', 'asset_payload', 'opening_cost',
        'opening_accumulated_depreciation', 'opening_accumulated_impairment', 'opening_book_value', 'asset_id',
    ];

    protected function casts(): array
    {
        return [
            'asset_payload' => 'array', 'opening_cost' => 'decimal:2',
            'opening_accumulated_depreciation' => 'decimal:2', 'opening_accumulated_impairment' => 'decimal:2',
            'opening_book_value' => 'decimal:2',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AssetOpeningBalanceBatch::class, 'asset_opening_balance_batch_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
