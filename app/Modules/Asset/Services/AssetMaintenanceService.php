<?php

namespace App\Modules\Asset\Services;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetHistory;
use App\Modules\Asset\Models\AssetMaintenanceRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AssetMaintenanceService
{
    public function create(Branch $branch, array $attributes, User $actor): AssetMaintenanceRequest
    {
        return DB::transaction(function () use ($branch, $attributes, $actor): AssetMaintenanceRequest {
            $asset = Asset::query()->where('branch_id', $branch->id)->lockForUpdate()->findOrFail($attributes['asset_id']);
            if (in_array($asset->status, ['HELD_FOR_DISPOSAL', 'DISPOSED', 'WRITTEN_OFF'], true)) {
                throw ValidationException::withMessages(['asset_id' => 'สินทรัพย์สถานะนี้ไม่สามารถสร้างงานซ่อมได้']);
            }

            return AssetMaintenanceRequest::query()->create([
                ...$attributes, 'branch_id' => $branch->id, 'reported_by' => $actor->id, 'status' => 'OPEN',
                'is_under_warranty' => (bool) ($attributes['is_under_warranty'] ?? false), 'takes_asset_out_of_service' => (bool) ($attributes['takes_asset_out_of_service'] ?? false),
                'created_by' => $actor->id, 'updated_by' => $actor->id,
            ]);
        }, 3);
    }

    public function assign(AssetMaintenanceRequest $request, int $userId, User $actor): AssetMaintenanceRequest
    {
        return $this->transition($request, ['OPEN', 'ASSIGNED', 'WAITING_PARTS'], 'ASSIGNED', ['assigned_to_user_id' => $userId, 'assigned_by' => $actor->id, 'assigned_at' => now(), 'updated_by' => $actor->id]);
    }

    public function start(AssetMaintenanceRequest $request, array $attributes, User $actor): AssetMaintenanceRequest
    {
        return DB::transaction(function () use ($request, $attributes, $actor): AssetMaintenanceRequest {
            $request = $this->lock($request);
            if (! in_array($request->status, ['ASSIGNED', 'WAITING_PARTS'], true)) {
                throw ValidationException::withMessages(['status' => 'เริ่มซ่อมได้เฉพาะงานที่มอบหมายแล้วหรือรออะไหล่']);
            }
            $isFirstStart = $request->started_at === null;
            $request->update(['status' => 'IN_PROGRESS', 'started_date' => $attributes['started_date'], 'started_by' => $actor->id, 'started_at' => now(), 'updated_by' => $actor->id]);
            if ($request->takes_asset_out_of_service && $isFirstStart) {
                $this->changeAssetStatus($request, 'UNDER_REPAIR', 'เริ่มซ่อม: '.$request->document_number, $actor);
            }

            return $request->fresh();
        }, 3);
    }

    public function waitingParts(AssetMaintenanceRequest $request, User $actor): AssetMaintenanceRequest
    {
        return $this->transition($request, ['ASSIGNED', 'IN_PROGRESS'], 'WAITING_PARTS', ['updated_by' => $actor->id]);
    }

    public function complete(AssetMaintenanceRequest $request, array $attributes, User $actor): AssetMaintenanceRequest
    {
        return DB::transaction(function () use ($request, $attributes, $actor): AssetMaintenanceRequest {
            $request = $this->lock($request);
            if (! in_array($request->status, ['ASSIGNED', 'IN_PROGRESS', 'WAITING_PARTS'], true)) {
                throw ValidationException::withMessages(['status' => 'ปิดงานได้เฉพาะงานที่ได้รับมอบหมายหรือกำลังดำเนินการ']);
            }
            $request->update([...$attributes, 'status' => 'COMPLETED', 'completed_by' => $actor->id, 'completed_at' => now(), 'updated_by' => $actor->id]);
            if ($request->takes_asset_out_of_service && $request->started_at) {
                $this->changeAssetStatus($request, 'ACTIVE', 'ปิดงานซ่อม: '.$request->document_number, $actor);
            }

            return $request->fresh();
        }, 3);
    }

    public function cancel(AssetMaintenanceRequest $request, string $reason, User $actor): AssetMaintenanceRequest
    {
        if (mb_strlen(trim($reason)) < 10) {
            throw ValidationException::withMessages(['cancellation_reason' => 'เหตุผลการยกเลิกต้องมีอย่างน้อย 10 ตัวอักษร']);
        }

        return DB::transaction(function () use ($request, $reason, $actor): AssetMaintenanceRequest {
            $request = $this->lock($request);
            if (! in_array($request->status, ['OPEN', 'ASSIGNED', 'IN_PROGRESS', 'WAITING_PARTS'], true)) {
                throw ValidationException::withMessages(['status' => 'สถานะงานไม่พร้อมสำหรับขั้นตอนนี้']);
            }
            if ($request->takes_asset_out_of_service && $request->started_at) {
                $this->changeAssetStatus($request, 'ACTIVE', 'ยกเลิกงานซ่อม: '.$request->document_number, $actor);
            }
            $request->update(['status' => 'CANCELLED', 'cancelled_by' => $actor->id, 'cancelled_at' => now(), 'cancellation_reason' => trim($reason), 'updated_by' => $actor->id]);

            return $request->fresh();
        }, 3);
    }

    private function transition(AssetMaintenanceRequest $request, array $from, string $to, array $values): AssetMaintenanceRequest
    {
        return DB::transaction(function () use ($request, $from, $to, $values): AssetMaintenanceRequest {
            $request = $this->lock($request);
            if (! in_array($request->status, $from, true)) {
                throw ValidationException::withMessages(['status' => 'สถานะงานไม่พร้อมสำหรับขั้นตอนนี้']);
            }
            $request->update(['status' => $to, ...$values]);

            return $request->fresh();
        }, 3);
    }

    private function changeAssetStatus(AssetMaintenanceRequest $request, string $status, string $reason, User $actor): void
    {
        $asset = Asset::query()->lockForUpdate()->findOrFail($request->asset_id);
        if ($status === 'UNDER_REPAIR' && $asset->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['asset_id' => 'นำสินทรัพย์ออกจากการใช้งานได้เฉพาะสินทรัพย์ ACTIVE']);
        }
        if ($status === 'ACTIVE' && $asset->status !== 'UNDER_REPAIR') {
            throw ValidationException::withMessages(['asset_id' => 'สินทรัพย์ไม่ได้อยู่ระหว่างซ่อมจากงานนี้']);
        }
        $before = $asset->only(['branch_id', 'location_id', 'custodian_user_id', 'status', 'status_reason']);
        $asset->update(['status' => $status, 'status_reason' => $reason, 'updated_by' => $actor->id]);
        AssetHistory::query()->create(['asset_id' => $asset->id, 'event_type' => $status === 'UNDER_REPAIR' ? 'MAINTENANCE_STARTED' : 'MAINTENANCE_COMPLETED', 'occurred_at' => now(), 'source_type' => 'ASSET_MAINTENANCE', 'source_id' => $request->id, 'source_document_number' => $request->document_number, 'actor_id' => $actor->id, 'reason' => $reason, 'old_branch_id' => $asset->branch_id, 'new_branch_id' => $asset->branch_id, 'old_location_id' => $asset->location_id, 'new_location_id' => $asset->location_id, 'old_custodian_user_id' => $asset->custodian_user_id, 'new_custodian_user_id' => $asset->custodian_user_id, 'old_status' => $before['status'], 'new_status' => $asset->status, 'old_values' => $before, 'new_values' => $asset->only(['branch_id', 'location_id', 'custodian_user_id', 'status', 'status_reason'])]);
    }

    private function lock(AssetMaintenanceRequest $request): AssetMaintenanceRequest
    {
        return AssetMaintenanceRequest::query()->lockForUpdate()->findOrFail($request->id);
    }
}
