<?php

namespace App\Modules\Production\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class ProductionOrderIssue extends Model
{
    protected $table = 'production_order_issues';

    protected $fillable = ['production_order_id', 'branch_id', 'warehouse_id', 'reported_by', 'resolved_by', 'severity', 'description', 'resolution_method', 'status', 'reported_at', 'resolved_at'];

    protected function casts(): array
    {
        return ['reported_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    public function order() { return $this->belongsTo(ProductionOrder::class, 'production_order_id'); }
    public function reporter() { return $this->belongsTo(User::class, 'reported_by'); }
    public function resolver() { return $this->belongsTo(User::class, 'resolved_by'); }
}
