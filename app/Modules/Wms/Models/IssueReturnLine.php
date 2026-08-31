<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class IssueReturnLine extends Model
{
    use SoftDeletes;

    protected $table = 'wms_issue_return_lines';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:8'];
    }

    public function return()
    {
        return $this->belongsTo(IssueReturn::class, 'return_id');
    }

    public function issueLine()
    {
        return $this->belongsTo(IssueLine::class, 'issue_line_id');
    }

    public function movement()
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function allocation()
    {
        return $this->belongsTo(CostAllocation::class, 'cost_allocation_id');
    }

    public function sourceAllocations()
    {
        return $this->hasMany(IssueReturnLineAllocation::class, 'return_line_id');
    }
}
