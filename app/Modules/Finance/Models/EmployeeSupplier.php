<?php

namespace App\Modules\Finance\Models;

use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

final class EmployeeSupplier extends Model
{
    protected $table = 'finance_employee_suppliers';

    protected $fillable = ['user_id', 'party_id', 'created_by'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function party()
    {
        return $this->belongsTo(Party::class);
    }
}
