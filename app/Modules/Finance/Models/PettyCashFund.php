<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class PettyCashFund extends Model
{
    use SoftDeletes;

    protected $table = 'finance_petty_cash_funds';

    protected $fillable = ['warehouse_id', 'name', 'bank_account_id', 'custodian_user_id', 'fund_limit', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['fund_limit' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function cashBankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class, 'bank_account_id');
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function vouchers(): HasMany
    {
        return $this->hasMany(PettyCashVoucher::class)->orderByDesc('document_date')->orderByDesc('id');
    }

    public function topUps(): HasMany
    {
        return $this->hasMany(PettyCashTopUp::class)->orderByDesc('document_date')->orderByDesc('id');
    }

    public function clearings(): HasMany
    {
        return $this->hasMany(PettyCashClearing::class)->orderByDesc('clearing_date')->orderByDesc('id');
    }
}
