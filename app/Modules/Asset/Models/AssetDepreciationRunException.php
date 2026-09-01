<?php

namespace App\Modules\Asset\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDepreciationRunException extends Model
{
    protected $fillable = ['asset_depreciation_run_id', 'asset_id', 'asset_number', 'asset_name', 'reason', 'created_by'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
