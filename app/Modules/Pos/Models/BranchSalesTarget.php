<?php

namespace App\Modules\Pos\Models;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class BranchSalesTarget extends Model
{
    use SoftDeletes;

    protected $table = 'pos_branch_sales_targets';

    protected $fillable = ['branch_id', 'period_start', 'period_end', 'target_sales_amount', 'target_gross_profit_amount', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'target_sales_amount' => 'decimal:2', 'target_gross_profit_amount' => 'decimal:2'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
