<?php

namespace App\Modules\Wms\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class TransferEvent extends Model
{
    protected $table = 'wms_transfer_events';

    protected $fillable = ['transfer_id', 'transfer_line_id', 'event_type', 'quantity', 'base_quantity', 'business_date', 'stock_movement_id', 'idempotency_key', 'source_reference', 'reason', 'created_by'];

    protected function casts(): array
    {
        return ['transfer_id' => 'integer', 'transfer_line_id' => 'integer', 'quantity' => 'decimal:8', 'base_quantity' => 'decimal:8', 'business_date' => 'date:Y-m-d', 'stock_movement_id' => 'integer', 'created_by' => 'integer'];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Transfer event เป็น immutable ledger'));
        static::deleting(fn (): never => throw new LogicException('Transfer event history cannot be deleted.'));
    }

    public function transfer()
    {
        return $this->belongsTo(Transfer::class);
    }

    public function line()
    {
        return $this->belongsTo(TransferLine::class, 'transfer_line_id');
    }

    public function stockMovement()
    {
        return $this->belongsTo(StockMovement::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
