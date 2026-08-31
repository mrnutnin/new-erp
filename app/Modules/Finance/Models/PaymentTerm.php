<?php

namespace App\Modules\Finance\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaymentTerm extends Model
{
    use SoftDeletes;

    protected $table = 'finance_payment_terms';

    protected $fillable = ['code', 'name', 'credit_days', 'due_rule', 'is_active', 'created_by'];

    protected function casts(): array
    {
        return ['credit_days' => 'integer', 'is_active' => 'boolean'];
    }

    public function createdBy()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
