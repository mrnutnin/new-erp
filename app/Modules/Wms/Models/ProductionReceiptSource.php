<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductionReceiptSource extends Model
{
    protected $table = 'wms_production_receipt_sources';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'receipt_document_id' => 'integer',
            'issue_document_id' => 'integer',
            'issue_line_id' => 'integer',
            'source_allocation_id' => 'integer',
            'source_allocation_revision' => 'integer',
            'position' => 'integer',
        ];
    }

    public function receipt()
    {
        return $this->belongsTo(InventoryAdjustmentDocument::class, 'receipt_document_id');
    }

    public function issue()
    {
        return $this->belongsTo(IssueDocument::class, 'issue_document_id');
    }

    public function sourceAllocation()
    {
        return $this->belongsTo(CostAllocation::class, 'source_allocation_id');
    }
}
