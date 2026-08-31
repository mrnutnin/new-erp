<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;

final class StockCountLine extends Model
{
    protected $table = 'wms_stock_count_lines';

    protected $fillable = ['document_id', 'line_number', 'item_id', 'uom_id', 'snapshot_quantity', 'counted_quantity', 'variance_quantity', 'snapshot_unit_cost', 'variance_value', 'note'];

    protected function casts(): array
    {
        return ['snapshot_quantity' => 'decimal:8', 'counted_quantity' => 'decimal:8', 'variance_quantity' => 'decimal:8', 'snapshot_unit_cost' => 'decimal:8', 'variance_value' => 'decimal:8'];
    }

    public function document()
    {
        return $this->belongsTo(StockCountDocument::class, 'document_id');
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function uom()
    {
        return $this->belongsTo(Uom::class);
    }
}
