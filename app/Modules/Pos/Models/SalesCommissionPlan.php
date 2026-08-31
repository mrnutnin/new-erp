<?php

namespace App\Modules\Pos\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SalesCommissionPlan extends Model
{
    use SoftDeletes;

    protected $table = 'pos_sales_commission_plans';

    protected $fillable = ['code', 'name', 'basis', 'rate', 'effective_from', 'effective_to', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['rate' => 'decimal:4', 'effective_from' => 'date', 'effective_to' => 'date', 'is_active' => 'boolean'];
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(SalesCommissionPlanAssignment::class, 'commission_plan_id');
    }

    public function commissionRecords(): HasMany
    {
        return $this->hasMany(CommissionRecord::class, 'commission_plan_id');
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
