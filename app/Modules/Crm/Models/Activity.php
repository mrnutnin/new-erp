<?php

namespace App\Modules\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Activity extends Model
{
    protected $table = 'crm_activities';

    protected $fillable = ['opportunity_id', 'type', 'subject', 'details', 'due_at', 'completed_at', 'assigned_to', 'created_by'];

    protected function casts(): array
    {
        return ['opportunity_id' => 'integer', 'assigned_to' => 'integer', 'created_by' => 'integer', 'due_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function opportunity(): BelongsTo { return $this->belongsTo(Opportunity::class); }
    public function assignee(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
