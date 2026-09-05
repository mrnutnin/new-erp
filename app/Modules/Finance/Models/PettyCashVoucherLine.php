<?php

namespace App\Modules\Finance\Models;

use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PettyCashVoucherLine extends Model
{
    protected $table = 'finance_petty_cash_voucher_lines';

    protected $fillable = ['petty_cash_voucher_id', 'line_number', 'expense_category_id', 'expense_category_code', 'expense_category_name', 'expense_account_id', 'expense_account_code', 'expense_account_name', 'description', 'receipt_reference', 'amount', 'tax_code_id', 'tax_code_code', 'tax_rate', 'tax_base', 'tax_amount', 'withholding_tax_code_id', 'withholding_tax_code', 'withholding_rate', 'withholding_base', 'withholding_amount'];

    protected function casts(): array
    {
        return ['line_number' => 'integer', 'amount' => 'decimal:2', 'tax_rate' => 'decimal:4', 'tax_base' => 'decimal:2', 'tax_amount' => 'decimal:2', 'withholding_rate' => 'decimal:4', 'withholding_base' => 'decimal:2', 'withholding_amount' => 'decimal:2'];
    }

    public function voucher(): BelongsTo
    {
        return $this->belongsTo(PettyCashVoucher::class, 'petty_cash_voucher_id');
    }

    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(OtherCategory::class, 'expense_category_id');
    }

    public function expenseAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'expense_account_id');
    }
}
