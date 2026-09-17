<?php

namespace App\Modules\Wms\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DocumentPhoto extends Model
{
    protected $table = 'wms_document_photos';

    protected $fillable = [
        'document_type', 'document_id', 'disk', 'path', 'original_name', 'mime_type', 'bytes', 'checksum', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['document_id' => 'integer', 'bytes' => 'integer', 'uploaded_by' => 'integer'];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
