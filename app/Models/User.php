<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'username',
        'employee_code',
        'email',
        'password',
        'is_active',
        'primary_branch_id',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class);
    }

    public function primaryBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'primary_branch_id');
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class);
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    public function hasPermission(string $permission): bool
    {
        $this->loadMissing('roles.permissions');

        // Purchasing is being extracted from the historical WMS surface. Keep
        // both permission namespaces equivalent during the staged cut-over so
        // existing WMS users do not lose access while Purchasing can seed and
        // use its own canonical codes.
        $codes = [$permission];
        if (str_starts_with($permission, 'purchasing.')) {
            $codes[] = 'wms.'.substr($permission, strlen('purchasing.'));
        } elseif (str_starts_with($permission, 'wms.')) {
            $codes[] = 'purchasing.'.substr($permission, strlen('wms.'));
        }

        return $this->roles
            ->where('is_active', true)
            ->contains(fn (Role $role) => $role->permissions->contains(
                fn (Permission $rolePermission): bool => in_array($rolePermission->code, $codes, true)
            ));
    }
}
