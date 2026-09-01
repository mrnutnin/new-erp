<?php

namespace Database\Seeders;

use App\Models\User;
use App\Modules\Asset\Models\Asset;
use App\Modules\Asset\Models\AssetMaintenanceRequest;
use App\Modules\Asset\Models\AssetMaintenanceSchedule;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

final class AssetMaintenanceMockSeeder extends Seeder
{
    public function run(): void
    {
        $assets = Asset::query()->whereIn('id', [1, 2, 3])->get()->keyBy('id');
        $users = User::query()->whereIn('id', [1, 2, 3, 4])->get()->keyBy('id');
        if ($assets->isEmpty() || $users->isEmpty()) {
            $this->command?->warn('ไม่พบสินทรัพย์หรือผู้ใช้สำหรับสร้างข้อมูล mockup');

            return;
        }

        $now = Carbon::now();
        $date = Carbon::today();
        $common = ['maintenance_type' => 'CORRECTIVE', 'priority' => 'NORMAL', 'issue' => 'ตรวจสอบและแก้ไขตามรอบการทดสอบระบบ', 'is_under_warranty' => false, 'takes_asset_out_of_service' => false, 'estimated_cost' => 1500, 'created_by' => 1, 'updated_by' => 1];
        $requests = [
            ['number' => 'MT-MOCK-OPEN', 'asset_id' => 1, 'status' => 'OPEN', 'reported_date' => $date->copy()->subDays(5)],
            ['number' => 'MT-MOCK-ASSIGNED', 'asset_id' => 2, 'status' => 'ASSIGNED', 'reported_date' => $date->copy()->subDays(4), 'assigned_to_user_id' => 2, 'assigned_by' => 1, 'assigned_at' => $now->copy()->subDays(3)],
            ['number' => 'MT-MOCK-INPROGRESS', 'asset_id' => 3, 'status' => 'IN_PROGRESS', 'reported_date' => $date->copy()->subDays(3), 'assigned_to_user_id' => 3, 'assigned_by' => 1, 'assigned_at' => $now->copy()->subDays(2), 'started_date' => $date->copy()->subDay(), 'started_by' => 3, 'started_at' => $now->copy()->subDay()],
            ['number' => 'MT-MOCK-WAITPARTS', 'asset_id' => 1, 'status' => 'WAITING_PARTS', 'reported_date' => $date->copy()->subDays(8), 'assigned_to_user_id' => 2, 'assigned_by' => 1, 'assigned_at' => $now->copy()->subDays(7), 'started_date' => $date->copy()->subDays(5), 'started_by' => 2, 'started_at' => $now->copy()->subDays(5)],
            ['number' => 'MT-MOCK-COMPLETED', 'asset_id' => 2, 'status' => 'COMPLETED', 'reported_date' => $date->copy()->subDays(15), 'assigned_to_user_id' => 3, 'assigned_by' => 1, 'assigned_at' => $now->copy()->subDays(14), 'started_date' => $date->copy()->subDays(13), 'started_by' => 3, 'started_at' => $now->copy()->subDays(13), 'completed_date' => $date->copy()->subDays(10), 'completed_by' => 3, 'completed_at' => $now->copy()->subDays(10), 'diagnosis' => 'พบอุปกรณ์เสื่อมสภาพจากการใช้งานต่อเนื่อง', 'resolution' => 'เปลี่ยนอุปกรณ์และทดสอบการใช้งานเรียบร้อย', 'actual_cost' => 1200, 'downtime_minutes' => 90],
            ['number' => 'MT-MOCK-CANCELLED', 'asset_id' => 3, 'status' => 'CANCELLED', 'reported_date' => $date->copy()->subDays(20), 'assigned_to_user_id' => 2, 'assigned_by' => 1, 'assigned_at' => $now->copy()->subDays(19), 'cancelled_by' => 1, 'cancelled_at' => $now->copy()->subDays(18), 'cancellation_reason' => 'ยกเลิกเพื่อรวมรายการกับงานซ่อมเลขที่ใหม่'],
        ];

        foreach ($requests as $request) {
            $asset = $assets->get($request['asset_id']);
            if (! $asset) {
                continue;
            }
            $attributes = $request;
            unset($attributes['number']);
            AssetMaintenanceRequest::query()->updateOrCreate(
                ['document_number' => $request['number']],
                [...$common, ...$attributes, 'branch_id' => $asset->branch_id, 'reported_by' => 1]
            );
        }

        $schedules = [
            ['asset_id' => 1, 'title' => 'ตรวจเช็กอุปกรณ์ประจำเดือน', 'interval_months' => 1, 'next_due_date' => $date->copy()->addDays(2), 'default_priority' => 'NORMAL', 'is_active' => true],
            ['asset_id' => 2, 'title' => 'ทำความสะอาดและตรวจสภาพรายไตรมาส', 'interval_months' => 3, 'next_due_date' => $date->copy()->subDays(2), 'default_priority' => 'HIGH', 'is_active' => true],
            ['asset_id' => 3, 'title' => 'ตรวจสอบความปลอดภัยประจำปี', 'interval_months' => 12, 'next_due_date' => $date->copy()->addMonths(4), 'default_priority' => 'LOW', 'is_active' => true],
            ['asset_id' => 1, 'title' => 'แผนบำรุงรักษาที่ปิดใช้งาน', 'interval_days' => 30, 'next_due_date' => $date->copy()->addDays(10), 'default_priority' => 'NORMAL', 'is_active' => false],
        ];

        foreach ($schedules as $schedule) {
            $asset = $assets->get($schedule['asset_id']);
            if (! $asset) {
                continue;
            }
            AssetMaintenanceSchedule::query()->updateOrCreate(
                ['asset_id' => $asset->id, 'title' => $schedule['title']],
                [...$schedule, 'branch_id' => $asset->branch_id, 'responsible_user_id' => 2, 'created_by' => 1, 'updated_by' => 1]
            );
        }

        $this->command?->info('สร้าง mockup งานซ่อม 6 สถานะ และแผนบำรุงรักษา 4 รายการแล้ว');
    }
}
