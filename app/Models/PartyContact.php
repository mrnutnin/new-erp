<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class PartyContact extends Model
{
    use SoftDeletes;

    protected $fillable = ['party_id', 'name', 'position', 'decision_role', 'phone', 'email', 'line_id', 'preferred_channel', 'contact_permission_status', 'lawful_basis', 'allow_phone', 'allow_email', 'allow_line', 'permission_recorded_at', 'permission_recorded_by', 'permission_note', 'is_primary', 'is_active', 'notes', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['allow_phone' => 'boolean', 'allow_email' => 'boolean', 'allow_line' => 'boolean', 'permission_recorded_at' => 'datetime', 'is_primary' => 'boolean', 'is_active' => 'boolean'];
    }

    public function party(): BelongsTo { return $this->belongsTo(Party::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
    public function permissionRecordedBy(): BelongsTo { return $this->belongsTo(User::class, 'permission_recorded_by'); }
}
