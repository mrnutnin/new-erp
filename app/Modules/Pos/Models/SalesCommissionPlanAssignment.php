<?php

namespace App\Modules\Pos\Models;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SalesCommissionPlanAssignment extends Model
{
    protected $table = 'pos_sales_commission_plan_assignments';

    protected $fillable = ['commission_plan_id', 'user_id', 'branch_id'];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SalesCommissionPlan::class, 'commission_plan_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
