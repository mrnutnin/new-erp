<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class ProductionOrderEvent extends Model
{
    protected $table = 'production_order_events';

    protected $fillable = ['production_order_id', 'event_type', 'source_type', 'source_id', 'payload', 'occurred_at', 'created_by'];

    protected function casts(): array
    {
        return ['production_order_id' => 'integer', 'payload' => 'array', 'occurred_at' => 'datetime', 'created_by' => 'integer'];
    }

    public function order() { return $this->belongsTo(ProductionOrder::class, 'production_order_id'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }
}
