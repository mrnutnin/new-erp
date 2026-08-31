<?php

namespace App\Modules\Pos\Models;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Finance\Models\CommissionPaymentRequest;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CommissionPaymentBatch extends Model
{
    protected $table = 'pos_sales_commission_payment_batches';

    protected $fillable = ['document_number', 'branch_id', 'period_from', 'period_to', 'total_amount', 'status', 'created_by', 'submitted_by', 'submitted_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason', 'cancellation_source'];

    protected function casts(): array
    {
        return ['branch_id' => 'integer', 'total_amount' => 'decimal:2', 'period_from' => 'date', 'period_to' => 'date', 'submitted_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submittedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CommissionPaymentBatchLine::class, 'payment_batch_id');
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(CommissionPayoutBatch::class, 'payment_batch_id');
    }

    public function paymentRequests(): HasMany
    {
        return $this->hasMany(CommissionPaymentRequest::class, 'payment_batch_id');
    }
}
