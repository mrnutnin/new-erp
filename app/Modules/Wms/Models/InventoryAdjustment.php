<?php

namespace App\Modules\Wms\Models;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Accounting\Models\JournalEntry;
use Illuminate\Database\Eloquent\Model;

final class InventoryAdjustment extends Model
{
    protected $table = 'wms_inventory_adjustments';

    protected $fillable = ['document_id', 'line_number', 'warehouse_id', 'item_id', 'uom_id', 'direction', 'status', 'reversal_status', 'quantity', 'value', 'business_date', 'reason', 'idempotency_key', 'stock_movement_id', 'cost_allocation_id', 'reversal_journal_entry_id', 'reversal_movement_id', 'reversal_allocation_id', 'created_by', 'approved_by', 'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_revision'];

    protected function casts(): array
    {
        return ['document_id' => 'integer', 'line_number' => 'integer', 'business_date' => 'date:Y-m-d', 'quantity' => 'decimal:8', 'value' => 'decimal:8', 'reversal_journal_entry_id' => 'integer', 'reversal_movement_id' => 'integer', 'reversal_allocation_id' => 'integer', 'reversed_by' => 'integer', 'reversed_at' => 'datetime', 'reversal_revision' => 'integer'];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function document()
    {
        return $this->belongsTo(InventoryAdjustmentDocument::class, 'document_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function uom()
    {
        return $this->belongsTo(Uom::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function movement()
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function allocation()
    {
        return $this->belongsTo(CostAllocation::class, 'cost_allocation_id');
    }

    public function reversalJournal()
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function reversalMovement()
    {
        return $this->belongsTo(StockMovement::class, 'reversal_movement_id');
    }

    public function reversalAllocation()
    {
        return $this->belongsTo(CostAllocation::class, 'reversal_allocation_id');
    }
}
