<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashAttachment extends Model
{
    protected $table = 'finance_petty_cash_attachments';

    protected $fillable = ['warehouse_id', 'subject_type', 'subject_id', 'file_type', 'disk', 'path', 'original_name', 'mime_type', 'bytes', 'checksum', 'uploaded_by'];

    protected function casts(): array
    {
        return ['bytes' => 'integer'];
    }

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function uploadedBy(): BelongsTo { return $this->belongsTo(User::class, 'uploaded_by'); }
}
