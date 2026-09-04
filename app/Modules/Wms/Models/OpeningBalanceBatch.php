<?php

namespace App\Modules\Wms\Models;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;

class OpeningBalanceBatch extends Model
{
    protected $table = 'wms_opening_balance_batches';

    protected $fillable = ['warehouse_id', 'cutover_date', 'costing_method', 'status', 'total_value', 'source_reference', 'notes', 'idempotency_key', 'posted_at', 'created_by', 'posted_by'];

    protected function casts(): array
    {
        return ['warehouse_id' => 'integer', 'cutover_date' => 'date:Y-m-d', 'total_value' => 'decimal:8', 'posted_at' => 'datetime', 'created_by' => 'integer', 'posted_by' => 'integer'];
    }

    public function warehouse() { return $this->belongsTo(Warehouse::class); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
    public function poster() { return $this->belongsTo(User::class, 'posted_by'); }
    public function lines() { return $this->hasMany(OpeningBalanceLine::class, 'batch_id'); }
}
