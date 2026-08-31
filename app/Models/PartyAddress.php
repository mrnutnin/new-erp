<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PartyAddress extends Model
{
    use SoftDeletes;

    protected $fillable = ['party_id', 'address_type', 'label', 'recipient_name', 'address_line', 'district', 'amphoe', 'province', 'postal_code', 'phone', 'is_default', 'is_active'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'is_active' => 'boolean'];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}
