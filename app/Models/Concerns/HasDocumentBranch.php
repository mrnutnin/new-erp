<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

trait HasDocumentBranch
{
    public static function bootHasDocumentBranch(): void
    {
        static::saving(function (Model $model): void {
            $warehouseId = (int) $model->getAttribute('warehouse_id');
            $branchId = Warehouse::query()->whereKey($warehouseId)->value('branch_id');

            if (! $branchId) {
                throw ValidationException::withMessages(['warehouse_id' => 'คลังที่เลือกไม่มีสาขาที่ใช้งานได้']);
            }

            if ($model->getAttribute('branch_id') !== null && (int) $model->getAttribute('branch_id') !== (int) $branchId) {
                throw ValidationException::withMessages(['warehouse_id' => 'คลังที่เลือกต้องอยู่ภายใต้สาขาเดียวกับเอกสาร']);
            }

            $model->setAttribute('branch_id', $branchId);
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
