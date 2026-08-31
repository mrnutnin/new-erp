<?php

namespace App\Modules\Accounting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountType extends Model
{
    protected $fillable = [
        'code',
        'name',
        'normal_balance',
        'statement_section',
        'sort_order',
    ];

    public function accounts(): HasMany
    {
        return $this->hasMany(Account::class);
    }
}
