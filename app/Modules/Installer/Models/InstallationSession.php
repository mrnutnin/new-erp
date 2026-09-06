<?php

namespace App\Modules\Installer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstallationSession extends Model
{
    protected $fillable = ['status', 'progress', 'started_by', 'started_at', 'completed_at', 'go_live_at', 'metadata'];

    protected function casts(): array
    {
        return ['progress' => 'integer', 'metadata' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime', 'go_live_at' => 'datetime'];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(InstallationStep::class);
    }

    public function checklists(): HasMany
    {
        return $this->hasMany(InstallationChecklist::class);
    }
}
