<?php

namespace App\Modules\Wms\Models;

use App\Models\Concerns\HasDocumentBranch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;

final class InventoryAdjustmentDocument extends Model
{
    use HasDocumentBranch;

    protected $table = 'wms_inventory_adjustment_documents';

    protected $fillable = ['warehouse_id', 'branch_id', 'document_number', 'document_date', 'direction', 'status', 'reversal_status', 'reason', 'idempotency_key', 'created_by', 'approved_by', 'posted_by', 'reversed_by', 'reversed_at', 'reversal_reason', 'reversal_revision'];

    protected function casts(): array
    {
        return ['document_date' => 'date:Y-m-d', 'created_by' => 'integer', 'approved_by' => 'integer', 'posted_by' => 'integer', 'reversed_by' => 'integer', 'reversed_at' => 'datetime', 'reversal_revision' => 'integer'];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines()
    {
        return $this->hasMany(InventoryAdjustment::class, 'document_id')->orderBy('line_number');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function poster()
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
