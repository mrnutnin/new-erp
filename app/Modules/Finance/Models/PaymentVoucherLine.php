<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentVoucherLine extends Model
{
    protected $table = 'finance_payment_voucher_lines';

    protected $fillable = ['payment_voucher_id', 'line_number', 'open_item_id', 'open_item_document_number', 'open_item_original_amount', 'amount', 'description', 'allocation_key'];

    protected function casts(): array
    {
        return ['line_number' => 'integer', 'open_item_id' => 'integer', 'open_item_original_amount' => 'decimal:2', 'amount' => 'decimal:2'];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id');
    }

    public function openItem(): BelongsTo
    {
        return $this->belongsTo(OpenItem::class);
    }
}
