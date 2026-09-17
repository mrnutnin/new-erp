<?php

namespace App\Modules\Crm\Models;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

final class SalesTeam extends Model
{
    use SoftDeletes;
    protected $table = 'crm_sales_teams';
    protected $fillable = ['branch_id', 'name', 'manager_id', 'is_active', 'created_by', 'updated_by'];
    protected function casts(): array { return ['branch_id'=>'integer','manager_id'=>'integer','is_active'=>'boolean']; }
    public function branch(): BelongsTo { return $this->belongsTo(Branch::class); }
    public function manager(): BelongsTo { return $this->belongsTo(User::class, 'manager_id'); }
    public function members(): BelongsToMany { return $this->belongsToMany(User::class, 'crm_sales_team_members', 'team_id', 'user_id')->withTimestamps(); }
}
