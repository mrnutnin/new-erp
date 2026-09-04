<?php

namespace App\Modules\Accounting\Models;

use App\Modules\Finance\Models\BankAccount;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BankStatement extends Model
{
    protected $table = 'accounting_bank_statements';

    protected $fillable = ['bank_account_id', 'fiscal_period_id', 'statement_date', 'opening_balance', 'closing_balance', 'source_file_name', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['statement_date' => 'date', 'opening_balance' => 'decimal:2', 'closing_balance' => 'decimal:2'];
    }

    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('line_number');
    }
}
