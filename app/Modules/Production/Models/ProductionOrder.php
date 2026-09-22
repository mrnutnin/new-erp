<?php

namespace App\Modules\Production\Models;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Pos\Models\SalesOrder;
use App\Modules\Pos\Models\SalesOrderLine;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

final class ProductionOrder extends Model
{
    use SoftDeletes;

    protected $table = 'production_orders';

    protected $fillable = [
        'branch_id', 'issue_warehouse_id', 'receipt_warehouse_id', 'document_number', 'order_type', 'status',
        'sales_order_id', 'sales_order_line_id', 'source_revision', 'required_delivery_date', 'required_delivery_at', 'customer_specification',
        'finished_item_id', 'uom_id', 'planned_quantity', 'completed_quantity', 'reject_quantity', 'bom_revision_id',
        'planned_start_date', 'planned_finish_date', 'planned_start_at', 'planned_finish_at', 'responsible_user_id', 'notes', 'released_at', 'released_by', 'held_at', 'held_by', 'hold_reason', 'resumed_at', 'resumed_by',
        'started_at', 'started_by', 'completed_at', 'completed_by', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
        'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'branch_id' => 'integer', 'issue_warehouse_id' => 'integer', 'receipt_warehouse_id' => 'integer',
            'sales_order_id' => 'integer', 'sales_order_line_id' => 'integer', 'source_revision' => 'integer',
            'required_delivery_date' => 'date:Y-m-d', 'required_delivery_at' => 'datetime', 'planned_start_at' => 'datetime', 'planned_finish_at' => 'datetime', 'finished_item_id' => 'integer', 'uom_id' => 'integer',
            'planned_quantity' => 'decimal:8', 'completed_quantity' => 'decimal:8', 'reject_quantity' => 'decimal:8',
            'bom_revision_id' => 'integer', 'planned_start_date' => 'date:Y-m-d', 'planned_finish_date' => 'date:Y-m-d',
            'responsible_user_id' => 'integer', 'released_at' => 'datetime', 'started_at' => 'datetime', 'held_at' => 'datetime', 'held_by' => 'integer', 'resumed_at' => 'datetime', 'resumed_by' => 'integer',
            'completed_at' => 'datetime', 'cancelled_at' => 'datetime', 'created_by' => 'integer', 'updated_by' => 'integer',
        ];
    }

    public function branch() { return $this->belongsTo(Branch::class); }
    public function issueWarehouse() { return $this->belongsTo(Warehouse::class, 'issue_warehouse_id'); }
    public function receiptWarehouse() { return $this->belongsTo(Warehouse::class, 'receipt_warehouse_id'); }
    public function salesOrder() { return $this->belongsTo(SalesOrder::class); }
    public function salesOrderLine() { return $this->belongsTo(SalesOrderLine::class); }
    public function finishedItem() { return $this->belongsTo(Item::class, 'finished_item_id'); }
    public function uom() { return $this->belongsTo(Uom::class); }
    public function bomRevision() { return $this->belongsTo(BomRevision::class, 'bom_revision_id'); }
    public function materials() { return $this->hasMany(ProductionOrderMaterial::class, 'production_order_id')->orderBy('line_number'); }
    public function events() { return $this->hasMany(ProductionOrderEvent::class, 'production_order_id')->latest('occurred_at'); }
    public function issues() { return $this->hasMany(ProductionOrderIssue::class, 'production_order_id')->latest('reported_at'); }
    public function operations() { return $this->hasMany(ProductionOrderOperation::class, 'production_order_id')->orderBy('sequence'); }
    public function scraps() { return $this->hasMany(ProductionOrderScrap::class, 'production_order_id')->latest('id'); }
    public function responsibleUser() { return $this->belongsTo(User::class, 'responsible_user_id'); }
}
