<?php

namespace App\Modules\Crm\Models;

use App\Models\Branch;
use App\Models\Party;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CustomerAssignment extends Model
{
    protected $table='crm_customer_assignments';
    protected $fillable=['party_id','branch_id','owner_id','team_id','territory','backup_owner_id','created_by','updated_by'];
    protected function casts():array{return ['party_id'=>'integer','branch_id'=>'integer','owner_id'=>'integer','team_id'=>'integer','backup_owner_id'=>'integer'];}
    public function party():BelongsTo{return $this->belongsTo(Party::class);}
    public function branch():BelongsTo{return $this->belongsTo(Branch::class);}
    public function owner():BelongsTo{return $this->belongsTo(User::class,'owner_id');}
    public function team():BelongsTo{return $this->belongsTo(SalesTeam::class,'team_id');}
    public function backupOwner():BelongsTo{return $this->belongsTo(User::class,'backup_owner_id');}
}
