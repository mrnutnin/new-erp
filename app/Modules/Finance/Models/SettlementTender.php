<?php

namespace App\Modules\Finance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettlementTender extends Model
{
    protected $table = 'finance_settlement_tenders';

    protected $fillable = ['bank_account_id', 'line_number', 'amount', 'reference'];

    protected function casts(): array
    {
        return ['bank_account_id' => 'integer', 'line_number' => 'integer', 'amount' => 'decimal:2'];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }
}
