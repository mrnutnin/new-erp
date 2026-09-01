<?php

namespace App\Modules\Asset\Models;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetTransferLine extends Model
{
    protected $fillable = ['asset_id', 'old_branch_id', 'new_branch_id', 'old_warehouse_id', 'new_warehouse_id', 'old_location_id', 'new_location_id', 'old_custodian_user_id', 'new_custodian_user_id', 'asset_number_snapshot', 'asset_name_snapshot'];

    public function transfer(): BelongsTo
    {
        return $this->belongsTo(AssetTransfer::class, 'asset_transfer_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function oldBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'old_branch_id');
    }

    public function newBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'new_branch_id');
    }

    public function oldWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'old_warehouse_id');
    }

    public function newWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'new_warehouse_id');
    }

    public function oldLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'old_location_id');
    }

    public function newLocation(): BelongsTo
    {
        return $this->belongsTo(AssetLocation::class, 'new_location_id');
    }

    public function oldCustodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'old_custodian_user_id');
    }

    public function newCustodian(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_custodian_user_id');
    }
}
