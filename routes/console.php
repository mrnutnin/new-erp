<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
use App\Models\User;
use App\Modules\Asset\Models\AssetMaintenanceSchedule;
use App\Modules\Wms\Jobs\DispatchPendingInventoryRecost;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Services\CostAllocationReviewService;
use App\Modules\Wms\Services\InventoryOpsSmokeWriter;
use App\Modules\Wms\Services\RecostQueueHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('wms:inventory-ops-smoke {--prefix=} {--actor=} {--confirm}', function (): void {
    $prefix = (string) $this->option('prefix');
    $actor = (int) $this->option('actor');
    try {
        $result = app(InventoryOpsSmokeWriter::class)->run($prefix, $actor, (bool) $this->option('confirm'));
        $this->info(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    } catch (Throwable $exception) {
        $this->error($exception->getMessage());
        throw $exception;
    }
})->purpose('สร้างและ Post Inventory -> GL OPS-SMOKE chain แบบ explicit และ idempotent');

Artisan::command('wms:legacy-repair-report {--dry-run} {--apply} {--allocation=} {--reason=} {--actor=}', function (): void {
    if (! $this->option('dry-run')) {
        $this->error('คำสั่งนี้เป็น read-only ต้องระบุ --dry-run เสมอ');

        return;
    }

    if ($this->option('apply')) {
        $id = (int) $this->option('allocation');
        $reason = (string) $this->option('reason');
        $actor = User::query()->find((int) $this->option('actor'));
        if ($id < 1 || ! $actor || mb_strlen(trim($reason)) < 10) {
            $this->error('--apply ต้องระบุ --allocation, --reason และ --actor ที่ถูกต้อง');

            return;
        }
        $allocation = CostAllocation::query()->findOrFail($id);
        $review = app(CostAllocationReviewService::class)->quarantine($allocation, ['source' => $allocation->stock_movement_id, 'journal' => $allocation->journal_entry_id, 'movement/reversal' => $allocation->metadata], $reason, $actor, true);
        $this->line(json_encode(['review_id' => $review->id, 'allocation_id' => $review->allocation_id, 'status' => $review->status, 'proposed_state' => $review->proposed_state], JSON_UNESCAPED_UNICODE));

        return;
    }

    $rows = DB::table('wms_cost_allocations as a')
        ->leftJoin('journal_entries as j', 'j.id', '=', 'a.journal_entry_id')
        ->leftJoin('warehouses as w', 'w.id', '=', 'a.warehouse_id')
        ->where('a.status', 'PENDING')->whereNotNull('a.journal_entry_id')
        ->orderBy('a.id')
        ->get(['a.id', 'a.allocation_type', 'a.cost_status', 'a.quantity', 'a.value', 'a.unit_cost', 'a.warehouse_id', 'w.code as warehouse_code', 'a.item_id', 'a.revision', 'a.idempotency_key', 'a.journal_entry_id', 'j.status as journal_status', 'j.source_type', 'j.source_event', 'j.source_id', 'j.entry_number', 'j.posting_hash']);

    $this->line(json_encode($rows->map(function ($row): array {
        return [
            'allocation_id' => $row->id,
            'source' => ['allocation_type' => $row->allocation_type, 'cost_status' => $row->cost_status, 'item_id' => $row->item_id, 'warehouse_id' => $row->warehouse_id, 'warehouse_code' => $row->warehouse_code, 'quantity' => $row->quantity, 'unit_cost' => $row->unit_cost, 'value' => $row->value],
            'journal' => ['id' => $row->journal_entry_id, 'number' => $row->entry_number, 'status' => $row->journal_status, 'source_type' => $row->source_type, 'source_event' => $row->source_event, 'source_id' => $row->source_id, 'posting_hash' => $row->posting_hash],
            'proposal' => 'REVIEW_REQUIRED',
            'expected_state' => 'REVIEW_REQUIRED',
            'evidence' => [
                'allocation_status' => $row->status ?? 'PENDING',
                'journal_status' => $row->journal_status,
                'journal_event' => $row->source_event,
                'movement_linkage' => 'ต้องตรวจ movement/source/reversal ให้ครบก่อนเปลี่ยนสถานะ',
            ],
            'reason' => $row->journal_status === 'REVERSED' ? 'Allocation ยัง PENDING แต่ Journal ถูก Reverse แล้ว' : 'Allocation ยัง PENDING แต่มี Journal POSTED ต้องตรวจ source/reversal linkage ก่อนปรับสถานะ',
            'idempotency_plan' => 'ห้าม update เดิม; ตรวจ allocation:'.$row->id.':revision:'.$row->revision.' และ journal hash ก่อนสร้าง transition ที่มี audit',
        ];
    })->values()->all(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
})->purpose('Read-only report for legacy PENDING allocations linked to Journal');

Schedule::call(fn () => app(RecostQueueHealth::class)->markStale())->hourly();

// Keep the recost queue moving without loading the whole ledger. The job
// itself caps each dispatch batch; these scheduler guards prevent duplicate
// safety-net dispatchers when multiple scheduler workers run together.
Schedule::job(new DispatchPendingInventoryRecost(100))
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

Artisan::command('asset:maintenance-alerts', function (): void {
    $today = today();
    $schedules = AssetMaintenanceSchedule::query()->where('is_active', true)->whereDate('next_due_date', '<=', $today->copy()->addDays(7))->get();
    $schedules->each(fn (AssetMaintenanceSchedule $schedule) => $schedule->update(['last_alerted_at' => now()]));
    $this->info("Maintenance alerts: {$schedules->count()}");
})->purpose('บันทึกการตรวจแผนบำรุงรักษาที่ใกล้ครบกำหนดหรือเกินกำหนด โดยไม่สร้างใบแจ้งซ่อม');

Schedule::command('asset:maintenance-alerts')->dailyAt('08:00')->withoutOverlapping()->onOneServer();
