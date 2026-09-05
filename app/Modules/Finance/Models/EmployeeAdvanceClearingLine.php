<?php

namespace App\Modules\Finance\Models;

use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeAdvanceClearingLine extends Model
{
    protected $table = 'finance_employee_advance_clearing_lines';
    protected $fillable = ['clearing_id', 'line_number', 'expense_category_id', 'expense_category_code', 'expense_category_name', 'expense_account_id', 'expense_account_code', 'expense_account_name', 'description', 'receipt_reference', 'amount', 'tax_code_id', 'tax_code_code', 'tax_rate', 'tax_amount', 'withholding_tax_code_id', 'withholding_tax_code', 'withholding_rate', 'withholding_amount'];
    protected function casts(): array { return ['line_number' => 'integer', 'amount' => 'decimal:2', 'tax_rate' => 'decimal:4', 'tax_amount' => 'decimal:2', 'withholding_rate' => 'decimal:4', 'withholding_amount' => 'decimal:2']; }
    public function clearing(): BelongsTo { return $this->belongsTo(EmployeeAdvanceClearing::class, 'clearing_id'); }
    public function expenseAccount(): BelongsTo { return $this->belongsTo(Account::class, 'expense_account_id'); }
    public function taxCode(): BelongsTo { return $this->belongsTo(TaxCode::class, 'tax_code_id'); }
}
