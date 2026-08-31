<?php

namespace App\Modules\Wms\Models;

use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class CostAllocation extends Model
{
    protected $table = 'wms_cost_allocations';

    protected $fillable = [
        'stock_movement_id', 'stock_cost_layer_id', 'recost_request_id', 'parent_allocation_id', 'journal_entry_id',
        'warehouse_id', 'item_id', 'uom_id', 'allocation_type', 'direction', 'cost_status', 'status', 'method',
        'policy_version', 'revision', 'quantity', 'unit_cost', 'value', 'business_date', 'idempotency_key', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'stock_movement_id' => 'integer', 'stock_cost_layer_id' => 'integer', 'recost_request_id' => 'integer',
            'parent_allocation_id' => 'integer', 'journal_entry_id' => 'integer', 'warehouse_id' => 'integer',
            'item_id' => 'integer', 'uom_id' => 'integer', 'revision' => 'integer', 'quantity' => 'decimal:8',
            'unit_cost' => 'decimal:8', 'value' => 'decimal:8', 'business_date' => 'date:Y-m-d', 'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $allocation): void {
            if (in_array($allocation->getOriginal('status'), ['POSTED', 'REVERSED'], true)) {
                throw new LogicException('Posted cost allocation is immutable. Create a delta or reversal instead.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Cost allocations cannot be deleted.'));
    }

    public function movement()
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class, 'warehouse_id');
    }

    public function layer()
    {
        return $this->belongsTo(StockCostLayer::class, 'stock_cost_layer_id');
    }

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_allocation_id');
    }

    public function journalLineLinks()
    {
        return $this->hasMany(CostAllocationJournalLine::class, 'allocation_id');
    }

    public function journalEntry()
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
