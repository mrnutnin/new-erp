<?php

namespace App\Modules\Wms\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CostRevaluationBatch extends Model
{
    protected $table = 'wms_cost_revaluation_batches';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'source_document_id' => 'integer', 'source_revision' => 'integer',
            'expected_root_lines' => 'integer', 'resolved_root_lines' => 'integer',
            'expected_partitions' => 'integer', 'completed_partitions' => 'integer',
            'failed_partitions' => 'integer', 'blockers' => 'array', 'trigger_snapshot' => 'array',
            'document_date' => 'date:Y-m-d', 'requested_by' => 'integer', 'heartbeat_at' => 'datetime',
        ];
    }

    public function runs(): HasMany { return $this->hasMany(CostRevaluationRun::class, 'batch_id'); }
    public function requester(): BelongsTo { return $this->belongsTo(User::class, 'requested_by'); }
}
