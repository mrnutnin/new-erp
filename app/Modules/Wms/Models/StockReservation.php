<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;

class StockReservation extends Model
{
    public const SOURCE_SALES_ORDER_LINE = 'SALES_ORDER_LINE';

    protected $table = 'wms_stock_reservations';

    protected $fillable = ['warehouse_id', 'item_id', 'uom_id', 'quantity', 'consumed_quantity', 'status', 'source_type', 'source_id', 'idempotency_key', 'created_by'];

    protected function casts(): array
    {
        return ['warehouse_id' => 'integer', 'item_id' => 'integer', 'uom_id' => 'integer', 'quantity' => 'decimal:8', 'consumed_quantity' => 'decimal:8', 'created_by' => 'integer'];
    }
}
