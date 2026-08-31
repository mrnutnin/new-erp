<?php

namespace App\Modules\Pos\Models;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class EmployeeSalesTarget extends Model
{
    use SoftDeletes;

    protected $table = 'pos_employee_sales_targets';

    protected $fillable = ['branch_id', 'user_id', 'period_start', 'period_end', 'sales_target', 'gross_profit_target', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'sales_target' => 'decimal:2', 'gross_profit_target' => 'decimal:2'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
