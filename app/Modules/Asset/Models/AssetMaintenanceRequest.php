<?php

namespace App\Modules\Asset\Models;

use App\Models\Branch;
use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetMaintenanceRequest extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'document_number', 'asset_id', 'branch_id', 'reported_date', 'reported_by', 'maintenance_type', 'priority', 'issue', 'diagnosis', 'resolution', 'vendor_party_id', 'is_under_warranty', 'planned_date', 'started_date', 'completed_date', 'downtime_minutes', 'estimated_cost', 'actual_cost', 'source_document_type', 'source_document_number', 'takes_asset_out_of_service', 'status', 'assigned_to_user_id', 'assigned_by', 'assigned_at', 'started_by', 'started_at', 'completed_by', 'completed_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'reported_date' => 'date', 'planned_date' => 'date', 'started_date' => 'date', 'completed_date' => 'date',
            'is_under_warranty' => 'boolean', 'takes_asset_out_of_service' => 'boolean', 'estimated_cost' => 'decimal:2', 'actual_cost' => 'decimal:2',
            'assigned_at' => 'datetime', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'cancelled_at' => 'datetime',
        ];
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'vendor_party_id');
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AssetAttachment::class, 'subject_id')->where('subject_type', 'ASSET_MAINTENANCE');
    }
}
