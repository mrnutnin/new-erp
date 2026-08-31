<?php

namespace App\Modules\Pos\Models;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PriceList extends Model
{
    use SoftDeletes;

    protected $table = 'pos_price_lists';

    protected $fillable = [
        'code', 'name', 'currency', 'branch_id', 'customer_group_code', 'priority',
        'effective_from', 'effective_to', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer',
            'priority' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PriceListItem::class);
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
