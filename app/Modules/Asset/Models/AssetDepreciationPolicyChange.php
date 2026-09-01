<?php

namespace App\Modules\Asset\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetDepreciationPolicyChange extends Model
{
    protected $fillable = [
        'asset_depreciation_book_id', 'effective_date', 'status', 'profile_snapshot', 'reason', 'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['effective_date' => 'date', 'profile_snapshot' => 'array', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function depreciationBook(): BelongsTo
    {
        return $this->belongsTo(AssetDepreciationBook::class, 'asset_depreciation_book_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
