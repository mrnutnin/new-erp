<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxCode;
use Illuminate\Database\Eloquent\Model;

final class WithholdingRealization extends Model
{
    protected $table = 'finance_withholding_realizations';

    protected $fillable = ['allocation_id', 'settlement_id', 'open_item_id', 'tax_code_id', 'account_id', 'direction', 'tax_base', 'tax_amount', 'settlement_date', 'created_by'];

    protected function casts(): array
    {
        return ['allocation_id' => 'integer', 'settlement_id' => 'integer', 'open_item_id' => 'integer', 'tax_code_id' => 'integer', 'account_id' => 'integer', 'tax_base' => 'decimal:2', 'tax_amount' => 'decimal:2', 'settlement_date' => 'date', 'created_by' => 'integer'];
    }

    public function allocation()
    {
        return $this->belongsTo(Allocation::class);
    }

    public function openItem()
    {
        return $this->belongsTo(OpenItem::class);
    }

    public function taxCode()
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
