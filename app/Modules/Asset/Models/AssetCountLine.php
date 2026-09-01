<?php

namespace App\Modules\Asset\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetCountLine extends Model
{
    protected $fillable = ['asset_id', 'asset_number_snapshot', 'asset_name_snapshot', 'expected_location_id', 'expected_custodian_user_id', 'scanned_code', 'found_location_id', 'found_custodian_user_id', 'result', 'note', 'follow_up_required', 'counted_at', 'counted_by'];

    protected function casts(): array
    {
        return ['follow_up_required' => 'boolean', 'counted_at' => 'datetime'];
    }

    public function count(): BelongsTo
    {
        return $this->belongsTo(AssetCount::class, 'asset_count_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function expectedLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'expected_location_id');
    }

    public function foundLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'found_location_id');
    }

    public function expectedCustodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'expected_custodian_user_id');
    }

    public function foundCustodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'found_custodian_user_id');
    }

    public function countedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'counted_by');
    }
}
