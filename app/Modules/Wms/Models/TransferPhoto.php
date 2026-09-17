<?php

namespace App\Modules\Wms\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class TransferPhoto extends Model
{
    protected $table = 'wms_transfer_photos';

    protected $fillable = [
        'transfer_id', 'stage', 'disk', 'path', 'original_name', 'mime_type', 'bytes', 'checksum', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['transfer_id' => 'integer', 'bytes' => 'integer', 'uploaded_by' => 'integer'];
    }

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(Transfer::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
