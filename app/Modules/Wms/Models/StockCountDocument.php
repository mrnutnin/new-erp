<?php

namespace App\Modules\Wms\Models;

use App\Models\Concerns\HasDocumentBranch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;

final class StockCountDocument extends Model
{
    use HasDocumentBranch;

    protected $table = 'wms_stock_count_documents';

    protected $fillable = ['warehouse_id', 'branch_id', 'document_number', 'document_date', 'status', 'reason', 'idempotency_key', 'created_by', 'approved_by', 'posted_by', 'adjustment_document_ids'];

    protected function casts(): array
    {
        return ['document_date' => 'date:Y-m-d', 'adjustment_document_ids' => 'array'];
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function lines()
    {
        return $this->hasMany(StockCountLine::class, 'document_id')->orderBy('line_number');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
