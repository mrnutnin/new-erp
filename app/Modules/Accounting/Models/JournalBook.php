<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;

class JournalBook extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'sequence_prefix',
        'sort_order',
        'is_system',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
