<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\TaxCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class OtherCategory extends Model
{
    use SoftDeletes;

    protected $table = 'finance_other_categories';

    protected $fillable = ['kind', 'code', 'name', 'account_id', 'tax_code_id', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function account()
    {
        return $this->belongsTo(Account::class);
    }

    public function taxCode()
    {
        return $this->belongsTo(TaxCode::class);
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
