<?php

namespace App\Modules\Asset\Models;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetCount extends Model
{
    use SoftDeletes;

    protected $fillable = ['document_number', 'branch_id', 'location_id', 'asset_category_id', 'freeze_date', 'status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['freeze_date' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AssetCountLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }
}
