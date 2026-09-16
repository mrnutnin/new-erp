<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Models\TaxCode;
use App\Modules\Wms\Models\Item;
use App\Modules\Wms\Models\Uom;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrderLine extends Model
{
    protected $table = 'purchase_order_lines';

    protected $fillable = ['purchase_order_id', 'purchase_requisition_line_id', 'line_number', 'item_id', 'uom_id', 'tax_code_id', 'description', 'quantity', 'unit_price', 'line_total', 'tax_rate', 'tax_base', 'tax_amount', 'gross_amount'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'line_total' => 'decimal:2', 'tax_rate' => 'decimal:4', 'tax_base' => 'decimal:2', 'tax_amount' => 'decimal:2', 'gross_amount' => 'decimal:2'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function purchaseRequisitionLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisitionLine::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function purchaseDocumentLines(): HasMany
    {
        return $this->hasMany(PurchaseDocumentLine::class);
    }
}
