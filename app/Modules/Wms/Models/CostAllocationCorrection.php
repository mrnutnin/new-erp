<?php

namespace App\Modules\Wms\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CostAllocationCorrection extends Model
{
    protected $table = 'wms_cost_allocation_corrections';

    protected $fillable = ['allocation_id', 'canonical_allocation_id', 'correction_type', 'reason', 'evidence', 'created_by', 'applied_at'];

    protected function casts(): array
    {
        return ['allocation_id' => 'integer', 'canonical_allocation_id' => 'integer', 'created_by' => 'integer', 'evidence' => 'array', 'applied_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Cost allocation correction is immutable. Create a new correction.'));
        static::deleting(fn (): never => throw new LogicException('Cost allocation correction cannot be deleted.'));
    }

    public function allocation()
    {
        return $this->belongsTo(CostAllocation::class, 'allocation_id');
    }

    public function canonicalAllocation()
    {
        return $this->belongsTo(CostAllocation::class, 'canonical_allocation_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
