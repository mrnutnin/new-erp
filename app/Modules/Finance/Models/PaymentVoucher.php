<?php

namespace App\Modules\Finance\Models;

use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentVoucher extends Model
{
    use SoftDeletes;

    protected $table = 'finance_payment_vouchers';

    protected $fillable = ['warehouse_id', 'voucher_type', 'document_number', 'document_date', 'party_id', 'bank_account_id', 'amount', 'description', 'status', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'settlement_id', 'created_by'];

    protected function casts(): array
    {
        return ['document_date' => 'date', 'amount' => 'decimal:2', 'submitted_at' => 'datetime', 'approved_at' => 'datetime'];
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }

    public function bankAccount()
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(PaymentVoucherLine::class, 'payment_voucher_id')->orderBy('line_number');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(Settlement::class);
    }
}
