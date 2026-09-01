<?php

namespace App\Modules\Asset\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDepreciationBook extends Model
{
    protected $fillable = ['asset_id', 'book_type', 'method', 'depreciable_cost', 'residual_value', 'useful_life_months', 'start_date', 'end_date', 'tax_rate_percent', 'tax_cost_cap', 'accumulated_depreciation', 'last_depreciation_date', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['depreciable_cost' => 'decimal:2', 'residual_value' => 'decimal:2', 'tax_rate_percent' => 'decimal:4', 'tax_cost_cap' => 'decimal:2', 'accumulated_depreciation' => 'decimal:2', 'start_date' => 'date', 'end_date' => 'date', 'last_depreciation_date' => 'date', 'is_active' => 'boolean'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
