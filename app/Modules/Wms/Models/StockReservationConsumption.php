<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;

class StockReservationConsumption extends Model
{
    protected $table = 'wms_stock_reservation_consumptions';

    protected $fillable = ['stock_reservation_id', 'stock_movement_id', 'quantity'];

    protected function casts(): array
    {
        return ['stock_reservation_id' => 'integer', 'stock_movement_id' => 'integer', 'quantity' => 'decimal:8'];
    }
}
