<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class ProductionOrderOperation extends Model
{
    protected $table = 'production_order_operations';
    protected $fillable = ['production_order_id', 'sequence', 'name', 'planned_minutes', 'status', 'started_at', 'completed_at', 'started_by', 'completed_by', 'notes'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'completed_at' => 'datetime', 'planned_minutes' => 'integer'];
    }

    public function order() { return $this->belongsTo(ProductionOrder::class, 'production_order_id'); }
    public function starter() { return $this->belongsTo(User::class, 'started_by'); }
    public function completer() { return $this->belongsTo(User::class, 'completed_by'); }
}
