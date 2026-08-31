<?php

namespace App\Modules\Pos\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CommissionPayoutLine extends Model
{
    protected $table = 'pos_sales_commission_payout_lines';

    protected $fillable = ['payout_batch_id', 'commission_record_id', 'amount'];

    protected function casts(): array
    {
        return ['payout_batch_id' => 'integer', 'commission_record_id' => 'integer', 'amount' => 'decimal:2'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(CommissionPayoutBatch::class, 'payout_batch_id');
    }

    public function commissionRecord(): BelongsTo
    {
        return $this->belongsTo(CommissionRecord::class, 'commission_record_id');
    }
}
