<?php

namespace App\Modules\Finance\Models;

use App\Models\Party;
use App\Models\User;
use App\Modules\Pos\Models\CommissionPaymentBatch;
use Illuminate\Database\Eloquent\Model;

final class CommissionPaymentRequest extends Model
{
    protected $table = 'finance_commission_payment_requests';

    protected $fillable = ['document_number', 'payment_batch_id', 'recipient_user_id', 'supplier_party_id', 'document_date', 'amount', 'status', 'payment_voucher_id', 'created_by', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'cancelled_by', 'cancelled_at', 'cancellation_reason'];

    protected function casts(): array
    {
        return ['payment_batch_id' => 'integer', 'recipient_user_id' => 'integer', 'supplier_party_id' => 'integer', 'payment_voucher_id' => 'integer', 'document_date' => 'date', 'amount' => 'decimal:2', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function paymentBatch()
    {
        return $this->belongsTo(CommissionPaymentBatch::class, 'payment_batch_id');
    }

    public function recipient()
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Party::class, 'supplier_party_id');
    }

    public function voucher()
    {
        return $this->belongsTo(PaymentVoucher::class, 'payment_voucher_id');
    }
}
