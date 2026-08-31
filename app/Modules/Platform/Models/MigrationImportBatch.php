<?php

namespace App\Modules\Platform\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationImportBatch extends Model
{
    protected $fillable = [
        'type',
        'template_version',
        'source_system',
        'original_filename',
        'checksum',
        'status',
        'total_rows',
        'valid_rows',
        'error_rows',
        'staged_rows',
        'created_by',
        'committed_by',
        'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'staged_rows' => 'array',
            'committed_at' => 'datetime',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
