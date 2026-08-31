<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderLine extends Model
{
    protected $fillable = ['purchase_order_id', 'purchase_requisition_line_id', 'line_number', 'item_id', 'uom_id', 'description', 'quantity', 'unit_price', 'line_total'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'line_total' => 'decimal:2'];
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function uom()
    {
        return $this->belongsTo(Uom::class);
    }

    public function purchaseRequisitionLine()
    {
        return $this->belongsTo(PurchaseRequisitionLine::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseDocumentLines(): HasMany
    {
        return $this->hasMany(PurchaseDocumentLine::class);
    }
}
