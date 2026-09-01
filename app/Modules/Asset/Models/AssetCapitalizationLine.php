<?php

namespace App\Modules\Asset\Models;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetCapitalizationLine extends Model
{
    protected $fillable = [
        'asset_capitalization_id', 'asset_id', 'line_number', 'source_type', 'source_id', 'source_line_id',
        'capitalized_cost', 'asset_account_id', 'book_profile_snapshot', 'tax_profile_snapshot', 'clearing_account_id', 'description',
    ];

    protected function casts(): array
    {
        return ['capitalized_cost' => 'decimal:2', 'book_profile_snapshot' => 'array', 'tax_profile_snapshot' => 'array'];
    }

    public function capitalization(): BelongsTo
    {
        return $this->belongsTo(AssetCapitalization::class, 'asset_capitalization_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function clearingAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'clearing_account_id');
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }
}
