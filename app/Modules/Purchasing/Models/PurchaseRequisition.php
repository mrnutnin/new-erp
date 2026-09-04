<?php

namespace App\Modules\Purchasing\Models;

use App\Models\Concerns\HasDocumentBranch;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseRequisition extends Model
{
    use HasDocumentBranch;

    protected $table = 'purchase_requisitions';
    protected $fillable = ['warehouse_id', 'branch_id', 'document_number', 'document_date', 'supplier_id', 'description', 'status', 'rejection_reason', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'voided_by', 'voided_at', 'void_reason', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['document_date' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function warehouse(): BelongsTo { return $this->belongsTo(Warehouse::class); }
    public function supplier(): BelongsTo { return $this->belongsTo(Party::class, 'supplier_id'); }
    public function lines(): HasMany { return $this->hasMany(PurchaseRequisitionLine::class)->orderBy('line_number'); }
    public function purchaseOrder() { return $this->hasOne(\App\Modules\Purchasing\Models\PurchaseOrder::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
