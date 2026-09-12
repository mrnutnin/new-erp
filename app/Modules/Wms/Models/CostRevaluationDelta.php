<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class CostRevaluationDelta extends Model
{
    protected $table = 'wms_cost_revaluation_deltas';

    protected $fillable = ['run_id', 'allocation_id', 'applied_cost_allocation_id', 'idempotency_key', 'status', 'old_unit_cost', 'new_unit_cost', 'delta_value', 'impact_bucket', 'target_event', 'target_warehouse_id', 'target_branch_id', 'stock_projection_delta_value', 'applied_at'];

    protected function casts(): array
    {
        return ['run_id' => 'integer', 'allocation_id' => 'integer', 'applied_cost_allocation_id' => 'integer', 'target_warehouse_id' => 'integer', 'target_branch_id' => 'integer', 'delta_value' => 'decimal:8', 'stock_projection_delta_value' => 'decimal:8', 'applied_at' => 'datetime'];
    }

    public function run(): BelongsTo { return $this->belongsTo(CostRevaluationRun::class, 'run_id'); }
    public function allocation(): BelongsTo { return $this->belongsTo(CostAllocation::class, 'allocation_id'); }
    public function appliedCostAllocation(): BelongsTo { return $this->belongsTo(CostAllocation::class, 'applied_cost_allocation_id'); }
}
