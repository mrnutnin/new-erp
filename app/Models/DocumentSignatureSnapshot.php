<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DocumentSignatureSnapshot extends Model
{
    protected $fillable = [
        'subject_type', 'subject_id', 'role', 'action', 'audit_log_id', 'user_id',
        'signer_name', 'signer_position', 'signed_at', 'signature_disk',
        'signature_path', 'signature_checksum', 'signature_mime_type',
    ];

    protected function casts(): array
    {
        return ['signed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
