<?php

namespace App\Modules\Purchasing\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LandedCostLine extends Model
{
    protected $table = 'purchasing_landed_cost_lines';

    protected $fillable = ['landed_cost_id', 'expense_source_type', 'expense_source_id', 'account_id', 'amount', 'tax_code_id', 'description'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:8'];
    }

    public function landedCost(): BelongsTo
    {
        return $this->belongsTo(LandedCost::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }
}
