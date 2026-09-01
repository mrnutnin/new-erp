<?php

namespace App\Modules\Asset\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetCategory extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code', 'name', 'description', 'is_depreciable', 'capitalization_threshold', 'book_method',
        'book_useful_life_months', 'book_residual_value_percent', 'tax_method', 'tax_useful_life_months',
        'tax_rate_percent', 'tax_cost_cap', 'asset_account_id', 'accumulated_depreciation_account_id',
        'depreciation_expense_account_id', 'accumulated_impairment_account_id', 'impairment_loss_account_id',
        'disposal_gain_account_id', 'disposal_loss_account_id', 'disposal_clearing_account_id', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'is_depreciable' => 'boolean',
            'capitalization_threshold' => 'decimal:2',
            'book_residual_value_percent' => 'decimal:4',
            'tax_rate_percent' => 'decimal:4',
            'tax_cost_cap' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function assetAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'asset_account_id');
    }

    public function accumulatedDepreciationAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_depreciation_account_id');
    }

    public function depreciationExpenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'depreciation_expense_account_id');
    }

    public function accumulatedImpairmentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'accumulated_impairment_account_id');
    }

    public function impairmentLossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'impairment_loss_account_id');
    }

    public function disposalGainAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_gain_account_id');
    }

    public function disposalLossAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_loss_account_id');
    }

    public function disposalClearingAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'disposal_clearing_account_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'asset_category_id');
    }
}
