<?php

namespace App\Modules\Asset\Models;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetMaintenanceSchedule extends Model
{
    use SoftDeletes;

    protected $fillable = ['asset_id', 'branch_id', 'title', 'interval_days', 'interval_months', 'next_due_date', 'last_completed_date', 'responsible_user_id', 'default_priority', 'is_active', 'last_alerted_at', 'last_completed_by', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['next_due_date' => 'date', 'last_completed_date' => 'date', 'is_active' => 'boolean', 'last_alerted_at' => 'datetime'];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }

    public function lastCompletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_completed_by');
    }
}
