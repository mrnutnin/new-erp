<?php

namespace App\Modules\Asset\Models;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetAttachment extends Model
{
    protected $fillable = [
        'branch_id', 'subject_type', 'subject_id', 'file_type', 'disk', 'path', 'original_name', 'mime_type', 'bytes', 'checksum', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['bytes' => 'integer'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
