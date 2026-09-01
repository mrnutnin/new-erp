<?php

namespace App\Modules\Asset\Models;

use App\Models\Branch;
use App\Models\Party;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Asset extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'asset_number', 'tag_number', 'barcode_value', 'branch_id', 'warehouse_id', 'location_id', 'custodian_user_id',
        'asset_category_id', 'parent_asset_id', 'name', 'description', 'brand', 'model', 'serial_number', 'manufacturer', 'registration_date',
        'acquisition_date', 'placed_in_service_date', 'supplier_id', 'warranty_end_date', 'insurance_policy_number', 'insurance_end_date',
        'original_cost', 'currency_code', 'exchange_rate', 'book_cost', 'book_accumulated_depreciation', 'book_accumulated_impairment',
        'book_value', 'status', 'is_depreciation_suspended', 'status_reason', 'source_type', 'source_id', 'source_line_id', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'registration_date' => 'date', 'acquisition_date' => 'date', 'placed_in_service_date' => 'date', 'warranty_end_date' => 'date', 'insurance_end_date' => 'date',
            'original_cost' => 'decimal:2', 'exchange_rate' => 'decimal:6', 'book_cost' => 'decimal:2', 'book_accumulated_depreciation' => 'decimal:2',
            'book_accumulated_impairment' => 'decimal:2', 'book_value' => 'decimal:2', 'is_depreciation_suspended' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class);
    }

    public function custodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'custodian_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_asset_id');
    }

    public function components(): HasMany
    {
        return $this->hasMany(self::class, 'parent_asset_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'supplier_id');
    }

    public function depreciationBooks(): HasMany
    {
        return $this->hasMany(AssetDepreciationBook::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AssetAttachment::class, 'subject_id')
            ->where('subject_type', 'ASSET');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(AssetHistory::class)->orderBy('occurred_at');
    }

    public function capitalizationLines(): HasMany
    {
        return $this->hasMany(AssetCapitalizationLine::class);
    }

    public function maintenanceRequests(): HasMany
    {
        return $this->hasMany(AssetMaintenanceRequest::class)->latest('reported_date')->latest('id');
    }

    public function valueEvents(): HasMany
    {
        return $this->hasMany(AssetValueEvent::class)->orderBy('event_date')->orderBy('id');
    }
}
