<?php

namespace App\Modules\Wms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CostRecalculationRequest extends Model
{
    protected $table = 'wms_cost_recalculation_requests';

    protected $fillable = ['idempotency_key', 'warehouse_id', 'item_id', 'trigger_movement_id', 'status', 'attempts', 'last_error', 'resolved_at'];

    protected function casts(): array
    {
        return ['warehouse_id' => 'integer', 'item_id' => 'integer', 'trigger_movement_id' => 'integer', 'attempts' => 'integer', 'resolved_at' => 'datetime'];
    }

    public function pendingLayers(): HasMany
    {
        return $this->hasMany(StockCostLayer::class, 'recost_request_id')
            ->where('cost_status', 'PENDING');
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', ['PENDING', 'PROCESSING', 'FAILED', 'STALE']);
    }

    public function markProcessing(): void
    {
        $this->forceFill([
            'status' => 'PROCESSING',
            'attempts' => ((int) $this->attempts) + 1,
            'last_error' => null,
        ])->save();
    }

    public function markFailed(string $message): void
    {
        $this->forceFill(['status' => 'FAILED', 'last_error' => mb_substr($message, 0, 65535)])->save();
    }

    public function markStale(string $message = 'เกิน SLA การคำนวณต้นทุนใหม่'): void
    {
        $this->forceFill(['status' => 'STALE', 'last_error' => $message])->save();
    }

    public function retry(): void
    {
        if (! in_array($this->status, ['FAILED', 'STALE'], true)) {
            return;
        }

        $this->forceFill([
            'status' => 'PENDING',
            'last_error' => null,
            'resolved_at' => null,
        ])->save();
    }
}
