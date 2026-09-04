<?php

namespace App\Modules\Purchasing\Models;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LandedCost extends Model
{
    protected $table = 'purchasing_landed_costs';

    protected $fillable = [
        'warehouse_id', 'document_number', 'business_date', 'status', 'allocation_basis',
        'currency_code', 'total_amount', 'idempotency_key', 'posted_at', 'posted_by',
        'created_by', 'updated_by', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d', 'total_amount' => 'decimal:8',
            'posted_at' => 'datetime', 'metadata' => 'array',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(LandedCostLine::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(LandedCostReceipt::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LandedCostAllocation::class);
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
