<?php

namespace App\Modules\Installer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationChecklist extends Model
{
    protected $fillable = [
        'installation_session_id',
        'step_code',
        'checklist_code',
        'type',
        'status',
        'completed_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(InstallationSession::class, 'installation_session_id');
    }
}
