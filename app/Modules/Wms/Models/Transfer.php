<?php

namespace App\Modules\Wms\Models;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use LogicException;

class Transfer extends Model
{
    protected $table = 'wms_transfers';

    protected $fillable = [
        'source_warehouse_id', 'destination_warehouse_id', 'document_number', 'document_date',
        'status', 'idempotency_key', 'dispatch_reason', 'reject_reason', 'created_by',
        'dispatched_by', 'dispatched_at', 'completed_by', 'completed_at',
        'void_reason', 'voided_by', 'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'source_warehouse_id' => 'integer', 'destination_warehouse_id' => 'integer',
            'document_date' => 'date:Y-m-d', 'dispatched_at' => 'datetime', 'completed_at' => 'datetime',
            'created_by' => 'integer', 'dispatched_by' => 'integer', 'completed_by' => 'integer',
            'voided_by' => 'integer', 'voided_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $transfer): void {
            if (in_array($transfer->getOriginal('status'), ['DISPATCHED', 'ACCEPTED', 'PARTIALLY_ACCEPTED', 'REJECTED'], true)
                && ($dirtyImmutable = array_diff(array_keys($transfer->getDirty()), ['status', 'dispatched_at', 'completed_at', 'completed_by', 'dispatched_by', 'reject_reason', 'void_reason', 'voided_by', 'voided_at', 'updated_at'])) !== []
                && $transfer->isDirty($dirtyImmutable)) {
                throw new LogicException('Transfer ที่เริ่มดำเนินการแล้วแก้ข้อมูลต้นฉบับไม่ได้');
            }
        });
        static::deleting(fn (): never => throw new LogicException('Transfer history cannot be deleted.'));
    }

    public function sourceWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'source_warehouse_id');
    }

    public function destinationWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    public function lines()
    {
        return $this->hasMany(TransferLine::class)->orderBy('line_number');
    }

    public function events()
    {
        return $this->hasMany(TransferEvent::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
