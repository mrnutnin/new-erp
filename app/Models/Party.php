<?php

namespace App\Models;

use App\Support\PartyNameNormalizer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Modules\Crm\Models\CustomerAssignment;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Party extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'type',
        'tax_id',
        'branch_code',
        'contact_name',
        'phone',
        'email',
        'address',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected static function booted():void
    {
        static::saving(fn(Party $party)=>$party->normalized_name=PartyNameNormalizer::normalize($party->name));
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function roles(): HasMany
    {
        return $this->hasMany(PartyRole::class);
    }

    public function customerRole(): HasOne
    {
        return $this->hasOne(PartyRole::class)->where('role', 'CUSTOMER');
    }

    public function supplierRole(): HasOne
    {
        return $this->hasOne(PartyRole::class)->where('role', 'SUPPLIER');
    }

    public function customerGroups(): BelongsToMany
    {
        return $this->belongsToMany(CustomerGroup::class, 'pos_customer_group_party');
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(PartyAddress::class);
    }

    public function customerAssignments():HasMany
    {
        return $this->hasMany(CustomerAssignment::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(PartyContact::class)->orderByDesc('is_primary')->orderBy('name');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
