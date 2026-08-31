<?php

namespace App\Modules\Pos\Models;

use App\Modules\Finance\Models\BankAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhysicalSaleTender extends Model
{
    protected $table = 'pos_physical_sale_tenders';

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
