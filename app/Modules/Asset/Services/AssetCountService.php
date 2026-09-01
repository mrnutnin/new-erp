<?php

namespace App\Modules\Asset\Services;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetCount;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssetCountService
{
    public function createDraft(Branch $branch, array $attributes, User $actor): AssetCount
    {
        return DB::transaction(function () use ($branch, $attributes, $actor): AssetCount {
            $assets = Asset::query()->where('branch_id', $branch->id)->where('status', 'ACTIVE')
                ->when($attributes['location_id'] ?? null, fn ($query, $id) => $query->where('location_id', $id))
                ->when($attributes['asset_category_id'] ?? null, fn ($query, $id) => $query->where('asset_category_id', $id))
                ->lockForUpdate()->get();
            if ($assets->isEmpty()) {
                throw ValidationException::withMessages(['scope' => 'ไม่พบสินทรัพย์ ACTIVE ตามขอบเขตที่เลือก']);
            }
            $count = AssetCount::query()->create([...$attributes, 'branch_id' => $branch->id, 'status' => 'DRAFT', 'created_by' => $actor->id, 'updated_by' => $actor->id]);
            foreach ($assets as $asset) {
                $count->lines()->create(['asset_id' => $asset->id, 'asset_number_snapshot' => $asset->asset_number, 'asset_name_snapshot' => $asset->name, 'expected_location_id' => $asset->location_id, 'expected_custodian_user_id' => $asset->custodian_user_id, 'result' => 'FOUND']);
            }

            return $count;
        }, 3);
    }

    public function submit(AssetCount $count, User $actor): AssetCount
    {
        if ($count->lines()->whereNull('counted_at')->exists()) {
            throw ValidationException::withMessages(['lines' => 'กรุณาบันทึกผลตรวจนับให้ครบทุกรายการก่อนส่งอนุมัติ']);
        }

        return $this->transition($count, 'DRAFT', ['status' => 'SUBMITTED', 'submitted_by' => $actor->id, 'submitted_at' => now(), 'updated_by' => $actor->id]);
    }

    public function approve(AssetCount $count, User $actor): AssetCount
    {
        return $this->transition($count, 'SUBMITTED', ['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_by' => $actor->id]);
    }

    public function recordLine(AssetCount $count, int $lineId, array $attributes, User $actor): void
    {
        DB::transaction(function () use ($count, $lineId, $attributes, $actor): void {
            $count = $this->lock($count);
            if ($count->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'บันทึกผลตรวจนับได้เฉพาะรายการร่าง']);
            }
            $updated = $count->lines()->whereKey($lineId)->update([
                ...$attributes,
                'follow_up_required' => in_array($attributes['result'], ['MISSING', 'DAMAGED', 'EXTRA'], true),
                'counted_at' => now(),
                'counted_by' => $actor->id,
            ]);
            if ($updated !== 1) {
                throw ValidationException::withMessages(['line' => 'ไม่พบรายการตรวจนับ']);
            }
        }, 3);
    }

    public function recordExtra(AssetCount $count, array $attributes, User $actor): void
    {
        DB::transaction(function () use ($count, $attributes, $actor): void {
            $count = $this->lock($count);
            if ($count->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'เพิ่มผลต่างได้เฉพาะรายการร่าง']);
            }
            if ($count->lines()->where('scanned_code', $attributes['scanned_code'])->exists()) {
                throw ValidationException::withMessages(['scanned_code' => 'รหัสที่สแกนถูกบันทึกในใบตรวจนับนี้แล้ว']);
            }
            $count->lines()->create([
                'asset_number_snapshot' => $attributes['scanned_code'],
                'asset_name_snapshot' => $attributes['asset_name'],
                'scanned_code' => $attributes['scanned_code'],
                'found_location_id' => $attributes['found_location_id'] ?? null,
                'found_custodian_user_id' => $attributes['found_custodian_user_id'] ?? null,
                'result' => 'EXTRA',
                'note' => $attributes['note'] ?? null,
                'follow_up_required' => true,
                'counted_at' => now(),
                'counted_by' => $actor->id,
            ]);
        }, 3);
    }

    public function cancel(AssetCount $count, string $reason, User $actor): AssetCount
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['cancellation_reason' => 'เหตุผลการยกเลิกต้องมีอย่างน้อย 10 ตัวอักษร']);
        }

        return DB::transaction(function () use ($count, $reason, $actor): AssetCount {
            $count = $this->lock($count);
            if (! in_array($count->status, ['DRAFT', 'SUBMITTED'], true)) {
                throw ValidationException::withMessages(['status' => 'ยกเลิกได้เฉพาะรายการร่างหรือรออนุมัติ']);
            }
            $count->update(['status' => 'CANCELLED', 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => trim($reason), 'updated_by' => $actor->id]);

            return $count;
        }, 3);
    }

    private function transition(AssetCount $count, string $from, array $values): AssetCount
    {
        return DB::transaction(function () use ($count, $from, $values): AssetCount {
            $count = $this->lock($count);
            if ($count->status !== $from) {
                throw ValidationException::withMessages(['status' => 'สถานะเอกสารไม่พร้อมสำหรับขั้นตอนนี้']);
            }
            $count->update($values);

            return $count;
        }, 3);
    }

    private function lock(AssetCount $count): AssetCount
    {
        return AssetCount::query()->lockForUpdate()->findOrFail($count->id);
    }
}
