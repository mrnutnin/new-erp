<?php

namespace App\Modules\Installer\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSeedVersion extends Model
{
    public $timestamps = false;

    protected $fillable = ['seed_code', 'version', 'installed_at', 'updated_at', 'metadata'];

    protected function casts(): array
    {
        return ['installed_at' => 'datetime', 'updated_at' => 'datetime', 'metadata' => 'array'];
    }
}
