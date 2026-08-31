<?php

namespace App\Modules\Wms\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class CostAllocationReview extends Model
{
    protected $table = 'wms_cost_allocation_reviews';

    protected $fillable = ['allocation_id', 'revision', 'status', 'proposed_state', 'evidence_hash', 'reason', 'actor_id', 'evidence'];

    protected function casts(): array
    {
        return ['revision' => 'integer', 'actor_id' => 'integer', 'evidence' => 'array'];
    }

    public function allocation()
    {
        return $this->belongsTo(CostAllocation::class, 'allocation_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
