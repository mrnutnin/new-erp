<?php

namespace App\Modules\Wms\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class CostRevaluationRun extends Model
{
    protected $table = 'wms_cost_revaluation_runs';

    protected $fillable = ['batch_id', 'partition_key', 'idempotency_key', 'root_allocation_id', 'status', 'proposed_unit_cost', 'posting_date', 'nodes_affected', 'nodes_scanned', 'expected_partitions', 'completed_partitions', 'failed_partitions', 'estimated_delta_value', 'shadow_snapshot', 'runtime_checkpoint', 'last_error', 'requested_by', 'approved_at', 'approved_by', 'applied_at', 'heartbeat_at'];

    protected function casts(): array
    {
        return ['batch_id' => 'integer', 'root_allocation_id' => 'integer', 'nodes_affected' => 'integer', 'nodes_scanned' => 'integer', 'expected_partitions' => 'integer', 'completed_partitions' => 'integer', 'failed_partitions' => 'integer', 'estimated_delta_value' => 'decimal:8', 'shadow_snapshot' => 'array', 'runtime_checkpoint' => 'array', 'posting_date' => 'date:Y-m-d', 'requested_by' => 'integer', 'approved_by' => 'integer', 'approved_at' => 'datetime', 'applied_at' => 'datetime', 'heartbeat_at' => 'datetime'];
    }

    public function rootAllocation(): BelongsTo { return $this->belongsTo(CostAllocation::class, 'root_allocation_id'); }
    public function deltas(): HasMany { return $this->hasMany(CostRevaluationDelta::class, 'run_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
    public function batch(): BelongsTo { return $this->belongsTo(CostRevaluationBatch::class, 'batch_id'); }
}
