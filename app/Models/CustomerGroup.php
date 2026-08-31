<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustomerGroup extends Model
{
    use SoftDeletes;

    protected $table = 'pos_customer_groups';

    protected $fillable = ['company_setting_id', 'code', 'name', 'is_active', 'created_by', 'updated_by'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function parties(): BelongsToMany
    {
        return $this->belongsToMany(Party::class, 'pos_customer_group_party');
    }

    public function scopeForCompany(Builder $query, int $companySettingId): Builder
    {
        return $query->where($this->qualifyColumn('company_setting_id'), $companySettingId);
    }
}
