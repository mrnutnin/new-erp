<?php

namespace App\Modules\Wms\Models;

use App\Models\Concerns\HasDocumentBranch;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Finance\Models\PaymentTerm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use HasDocumentBranch;

    protected $fillable = ['warehouse_id', 'branch_id', 'purchase_requisition_id', 'supplier_id', 'supplier_code', 'supplier_name', 'payment_term_id', 'document_number', 'document_date', 'expected_date', 'subtotal', 'total_amount', 'status', 'description', 'approved_by', 'approved_at', 'voided_by', 'voided_at', 'void_reason', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['document_date' => 'date', 'expected_date' => 'date', 'subtotal' => 'decimal:2', 'total_amount' => 'decimal:2', 'approved_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }

    public function purchaseRequisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class);
    }

    public function paymentTerm(): BelongsTo
    {
        return $this->belongsTo(PaymentTerm::class);
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class)->orderBy('line_number');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
