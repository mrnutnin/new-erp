<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;

final class IssueReturnLineAllocation extends Model
{
    protected $table = 'wms_issue_return_line_allocations';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:8'];
    }

    public function returnLine()
    {
        return $this->belongsTo(IssueReturnLine::class, 'return_line_id');
    }

    public function sourceAllocation()
    {
        return $this->belongsTo(CostAllocation::class, 'source_allocation_id');
    }

    public function movement()
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function allocation()
    {
        return $this->belongsTo(CostAllocation::class, 'cost_allocation_id');
    }
}
