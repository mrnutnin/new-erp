<?php

namespace App\Modules\Wms\Models;

use App\Models\Concerns\HasDocumentBranch;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;

class GoodsReceipt extends Model
{
    use HasDocumentBranch;

    protected $fillable = ['warehouse_id', 'branch_id', 'purchase_order_id', 'supplier_id', 'receipt_number', 'idempotency_key', 'business_date', 'status', 'description', 'approved_by', 'approved_at', 'voided_by', 'voided_at', 'void_reason', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['business_date' => 'date', 'approved_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function purchaseOrder()
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function supplier()
    {
        return $this->belongsTo(Party::class);
    }

    public function lines()
    {
        return $this->hasMany(GoodsReceiptLine::class);
    }

    public function approvedBy()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
