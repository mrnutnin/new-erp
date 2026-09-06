<?php

namespace App\Modules\Installer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationStep extends Model
{
    protected $fillable = ['installation_session_id', 'step_code', 'status', 'started_at', 'completed_at', 'error_message', 'metadata'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(InstallationSession::class, 'installation_session_id');
    }
}
