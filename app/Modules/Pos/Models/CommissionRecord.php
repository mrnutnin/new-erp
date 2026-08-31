<?php

namespace App\Modules\Pos\Models;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionRecord extends Model
{
    protected $table = 'pos_sales_commission_records';

    protected $fillable = [
        'commission_plan_id', 'recipient_user_id', 'warehouse_id', 'branch_id', 'physical_sale_id', 'physical_sale_line_id',
        'source_type', 'source_id', 'base_amount', 'rate_percent', 'commission_amount', 'status', 'calculated_at',
        'approved_by', 'approved_at', 'rejected_by', 'rejected_at', 'rejection_reason', 'paid_by', 'paid_at', 'reversed_by', 'reversed_at', 'reversal_reason',
        'reversal_of_id', 'snapshot', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'commission_plan_id' => 'integer', 'recipient_user_id' => 'integer', 'warehouse_id' => 'integer', 'branch_id' => 'integer',
            'physical_sale_id' => 'integer', 'physical_sale_line_id' => 'integer', 'base_amount' => 'decimal:2',
            'rate_percent' => 'decimal:4', 'commission_amount' => 'decimal:2', 'calculated_at' => 'datetime',
            'approved_at' => 'datetime', 'rejected_by' => 'integer', 'rejected_at' => 'datetime', 'paid_at' => 'datetime', 'reversed_at' => 'datetime', 'reversal_of_id' => 'integer',
            'snapshot' => 'array',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SalesCommissionPlan::class, 'commission_plan_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function physicalSale(): BelongsTo
    {
        return $this->belongsTo(PhysicalSale::class, 'physical_sale_id');
    }

    public function physicalSaleLine(): BelongsTo
    {
        return $this->belongsTo(PhysicalSaleLine::class, 'physical_sale_line_id');
    }

    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }

    public function payoutLines(): HasMany
    {
        return $this->hasMany(CommissionPayoutLine::class, 'commission_record_id');
    }

    public function paymentBatchLines(): HasMany
    {
        return $this->hasMany(CommissionPaymentBatchLine::class, 'commission_record_id');
    }
}
