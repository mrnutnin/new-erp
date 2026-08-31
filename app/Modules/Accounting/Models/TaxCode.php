<?php

namespace App\Modules\Accounting\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TaxCode extends Model
{
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'kind', 'rate', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'is_active' => 'boolean'];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
