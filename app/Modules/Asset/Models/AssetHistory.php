<?php

namespace App\Modules\Asset\Models;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetHistory extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'asset_id', 'event_type', 'occurred_at', 'source_type', 'source_id', 'source_document_number', 'actor_id', 'reason',
        'old_branch_id', 'new_branch_id', 'old_location_id', 'new_location_id', 'old_custodian_user_id', 'new_custodian_user_id',
        'old_status', 'new_status', 'old_values', 'new_values',
    ];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'old_values' => 'array', 'new_values' => 'array'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function oldBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'old_branch_id');
    }

    public function newBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'new_branch_id');
    }

    public function oldLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'old_location_id');
    }

    public function newLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'new_location_id');
    }

    public function oldCustodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'old_custodian_user_id');
    }

    public function newCustodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_custodian_user_id');
    }
}
