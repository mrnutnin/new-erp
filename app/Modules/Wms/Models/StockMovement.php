<?php

namespace App\Modules\Wms\Models;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class StockMovement extends Model
{
    protected $table = 'wms_stock_movements';

    protected $fillable = [
        'warehouse_id', 'item_id', 'uom_id', 'movement_type', 'direction', 'status',
        'quantity', 'base_quantity', 'business_date', 'source_type', 'source_id',
        'source_reference', 'transfer_key', 'idempotency_key', 'metadata', 'posted_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'warehouse_id' => 'integer', 'item_id' => 'integer', 'uom_id' => 'integer',
            'quantity' => 'decimal:8', 'base_quantity' => 'decimal:8', 'metadata' => 'array',
            'business_date' => 'date:Y-m-d', 'posted_at' => 'datetime', 'created_by' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $movement): void {
            if ($movement->getOriginal('status') === 'POSTED' && $movement->isDirty(array_diff(array_keys($movement->getDirty()), ['status', 'posted_at']))) {
                throw new LogicException('Posted stock movement is immutable. Create a reversal instead.');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Stock movements cannot be deleted.'));
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function uom()
    {
        return $this->belongsTo(Uom::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
