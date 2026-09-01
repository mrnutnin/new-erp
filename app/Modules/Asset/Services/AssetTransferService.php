<?php

namespace App\Modules\Asset\Services;

use App\Models\Branch;
use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetHistory;
use App\Modules\Asset\Models\AssetLocation;
use App\Modules\Asset\Models\AssetTransfer;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Asset movement changes operational custody only; branch GL reclass is deliberately disabled by policy. */
final class AssetTransferService
{
    public function createDraft(Branch $sourceBranch, array $attributes, User $actor): AssetTransfer
    {
        return DB::transaction(function () use ($sourceBranch, $attributes, $actor): AssetTransfer {
            $destination = $this->destination($attributes);
            $assets = $this->assetsForDraft($sourceBranch, $attributes);
            $transfer = AssetTransfer::query()->create([
                'document_number' => $attributes['document_number'],
                'source_branch_id' => $sourceBranch->id,
                'destination_branch_id' => $destination->id,
                'document_date' => $attributes['document_date'],
                'reason' => trim($attributes['reason']),
                'status' => 'DRAFT',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);
            foreach ($assets as $asset) {
                $transfer->lines()->create($this->lineValues($asset, $destination, $attributes));
            }

            return $transfer->fresh('lines');
        }, 3);
    }

    public function submit(AssetTransfer $transfer, User $actor): AssetTransfer
    {
        return $this->transition($transfer, 'DRAFT', ['status' => 'SUBMITTED', 'submitted_by' => $actor->id, 'submitted_at' => now(), 'updated_by' => $actor->id]);
    }

    public function approve(AssetTransfer $transfer, User $actor): AssetTransfer
    {
        return $this->transition($transfer, 'SUBMITTED', ['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_by' => $actor->id]);
    }

    public function cancel(AssetTransfer $transfer, string $reason, User $actor): AssetTransfer
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['cancellation_reason' => 'เหตุผลการยกเลิกต้องมีอย่างน้อย 10 ตัวอักษร']);
        }

        return DB::transaction(function () use ($transfer, $reason, $actor): AssetTransfer {
            $transfer = $this->lock($transfer);
            if (! in_array($transfer->status, ['DRAFT', 'SUBMITTED', 'APPROVED'], true)) {
                throw ValidationException::withMessages(['status' => 'ยกเลิกได้เฉพาะรายการร่าง รออนุมัติ หรือพร้อมลงรายการ']);
            }
            $transfer->update(['status' => 'CANCELLED', 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => trim($reason), 'updated_by' => $actor->id]);

            return $transfer->fresh('lines');
        }, 3);
    }

    public function post(AssetTransfer $transfer, User $actor): AssetTransfer
    {
        return DB::transaction(function () use ($transfer, $actor): AssetTransfer {
            $transfer = $this->lock($transfer)->load('lines');
            if ($transfer->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => 'ลงรายการได้เฉพาะใบโอนที่อนุมัติแล้ว']);
            }
            foreach ($transfer->lines as $line) {
                $asset = Asset::query()->lockForUpdate()->findOrFail($line->asset_id);
                $this->assertLineStillCurrent($asset, $line);
                $before = $asset->only(['branch_id', 'warehouse_id', 'location_id', 'custodian_user_id', 'status']);
                $asset->update(['branch_id' => $line->new_branch_id, 'warehouse_id' => $line->new_warehouse_id, 'location_id' => $line->new_location_id, 'custodian_user_id' => $line->new_custodian_user_id, 'updated_by' => $actor->id]);
                AssetHistory::query()->create([
                    'asset_id' => $asset->id,
                    'event_type' => 'TRANSFER_POSTED',
                    'occurred_at' => now(),
                    'source_type' => 'ASSET_TRANSFER',
                    'source_id' => $transfer->id,
                    'source_document_number' => $transfer->document_number,
                    'actor_id' => $actor->id,
                    'reason' => $transfer->reason,
                    'old_branch_id' => $before['branch_id'], 'new_branch_id' => $asset->branch_id,
                    'old_location_id' => $before['location_id'], 'new_location_id' => $asset->location_id,
                    'old_custodian_user_id' => $before['custodian_user_id'], 'new_custodian_user_id' => $asset->custodian_user_id,
                    'old_status' => $before['status'], 'new_status' => $asset->status,
                    'old_values' => $before, 'new_values' => $asset->only(['branch_id', 'warehouse_id', 'location_id', 'custodian_user_id', 'status']),
                ]);
            }
            // Policy default: one legal company; cross-branch operational moves do not create GL entries.
            $transfer->update(['status' => 'POSTED', 'posted_by' => $actor->id, 'posted_at' => now(), 'updated_by' => $actor->id]);

            return $transfer->fresh('lines');
        }, 3);
    }

    private function assetsForDraft(Branch $sourceBranch, array $attributes): Collection
    {
        $ids = collect($attributes['asset_ids'])->map(fn ($id) => (int) $id);
        $assets = Asset::query()->where('branch_id', $sourceBranch->id)->whereIn('id', $ids)->lockForUpdate()->get();
        if ($assets->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages(['asset_ids' => 'พบสินทรัพย์ที่ไม่ได้อยู่ในสาขาต้นทาง']);
        }
        if (! empty($attributes['include_components'])) {
            $children = Asset::query()->where('branch_id', $sourceBranch->id)->whereIn('parent_asset_id', $assets->pluck('id'))->lockForUpdate()->get();
            $assets = $assets->concat($children)->unique('id')->values();
        }
        foreach ($assets as $asset) {
            if ($asset->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['asset_ids' => "{$asset->asset_number} โอนได้เฉพาะสินทรัพย์สถานะ ACTIVE"]);
            }
        }

        return $assets;
    }

    private function destination(array $attributes): Branch
    {
        $branch = Branch::query()->where('is_active', true)->findOrFail($attributes['destination_branch_id']);
        foreach (['destination_warehouse_id' => Warehouse::class, 'destination_location_id' => AssetLocation::class] as $field => $model) {
            if (! empty($attributes[$field]) && ! $model::query()->whereKey($attributes[$field])->where('branch_id', $branch->id)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([$field => 'ปลายทางต้องอยู่ในสาขาปลายทางและยังใช้งานอยู่']);
            }
        }
        if (! empty($attributes['destination_custodian_user_id']) && ! User::query()->whereKey($attributes['destination_custodian_user_id'])->where('is_active', true)->whereHas('warehouses', fn ($query) => $query->where('branch_id', $branch->id)->where('is_active', true))->exists()) {
            throw ValidationException::withMessages(['destination_custodian_user_id' => 'ผู้ดูแลปลายทางไม่มีสิทธิ์ใช้งานในสาขาปลายทาง']);
        }

        return $branch;
    }

    private function lineValues(Asset $asset, Branch $destination, array $attributes): array
    {
        return ['asset_id' => $asset->id, 'old_branch_id' => $asset->branch_id, 'new_branch_id' => $destination->id, 'old_warehouse_id' => $asset->warehouse_id, 'new_warehouse_id' => $attributes['destination_warehouse_id'] ?? null, 'old_location_id' => $asset->location_id, 'new_location_id' => $attributes['destination_location_id'] ?? null, 'old_custodian_user_id' => $asset->custodian_user_id, 'new_custodian_user_id' => $attributes['destination_custodian_user_id'] ?? null, 'asset_number_snapshot' => $asset->asset_number, 'asset_name_snapshot' => $asset->name];
    }

    private function transition(AssetTransfer $transfer, string $from, array $values): AssetTransfer
    {
        return DB::transaction(function () use ($transfer, $from, $values): AssetTransfer {
            $transfer = $this->lock($transfer);
            if ($transfer->status !== $from) {
                throw ValidationException::withMessages(['status' => 'สถานะเอกสารไม่พร้อมสำหรับขั้นตอนนี้']);
            }
            $transfer->update($values);

            return $transfer->fresh('lines');
        }, 3);
    }

    private function lock(AssetTransfer $transfer): AssetTransfer
    {
        return AssetTransfer::query()->lockForUpdate()->findOrFail($transfer->id);
    }

    private function assertLineStillCurrent(Asset $asset, object $line): void
    {
        foreach (['branch_id' => 'old_branch_id', 'warehouse_id' => 'old_warehouse_id', 'location_id' => 'old_location_id', 'custodian_user_id' => 'old_custodian_user_id'] as $assetField => $snapshotField) {
            if ((int) ($asset->{$assetField} ?? 0) !== (int) ($line->{$snapshotField} ?? 0)) {
                throw ValidationException::withMessages(['status' => "{$asset->asset_number} เปลี่ยนตำแหน่งหรือผู้ดูแลหลังสร้างใบโอน กรุณาสร้างใบโอนใหม่"]);
            }
        }
        if ($asset->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['status' => "{$asset->asset_number} ไม่อยู่ในสถานะที่โอนได้"]);
        }
    }
}
