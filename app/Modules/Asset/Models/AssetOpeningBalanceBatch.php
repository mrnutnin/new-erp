<?php

namespace App\Modules\Asset\Models;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetOpeningBalanceBatch extends Model
{
    protected $fillable = [
        'branch_id', 'batch_reference', 'source_system', 'cutover_date', 'reconciliation_reference', 'status',
        'total_rows', 'total_opening_cost', 'total_accumulated_depreciation', 'total_accumulated_impairment',
        'created_by', 'validated_by', 'validated_at', 'committed_by', 'committed_at',
    ];

    protected function casts(): array
    {
        return [
            'cutover_date' => 'date', 'validated_at' => 'datetime', 'committed_at' => 'datetime',
            'total_opening_cost' => 'decimal:2', 'total_accumulated_depreciation' => 'decimal:2',
            'total_accumulated_impairment' => 'decimal:2',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(AssetOpeningBalanceLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function validatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function committedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'committed_by');
    }
}
