<?php

namespace App\Modules\Asset\Models;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class AssetLocation extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'branch_id', 'parent_id', 'warehouse_id', 'code', 'name', 'location_type', 'address', 'is_active', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class, 'location_id');
    }

    public function wouldCreateCycle(?int $parentId): bool
    {
        $visited = [];
        while ($parentId !== null && ! isset($visited[$parentId])) {
            if ($parentId === $this->getKey()) {
                return true;
            }

            $visited[$parentId] = true;
            $parentId = self::query()->withTrashed()->whereKey($parentId)->value('parent_id');
        }

        return false;
    }
}
