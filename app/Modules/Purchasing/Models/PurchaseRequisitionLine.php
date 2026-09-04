<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequisitionLine extends Model
{
    protected $table = 'purchase_requisition_lines';
    protected $fillable = ['purchase_requisition_id', 'line_number', 'item_id', 'uom_id', 'quantity', 'description'];

    protected function casts(): array
    {
        return ['line_number' => 'integer', 'item_id' => 'integer', 'uom_id' => 'integer', 'quantity' => 'decimal:4'];
    }

    public function requisition(): BelongsTo { return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id'); }
    public function item(): BelongsTo { return $this->belongsTo(Item::class); }
    public function uom(): BelongsTo { return $this->belongsTo(Uom::class); }
    public function purchaseOrderLines() { return $this->hasMany(\App\Modules\Purchasing\Models\PurchaseOrderLine::class, 'purchase_requisition_line_id'); }
}
