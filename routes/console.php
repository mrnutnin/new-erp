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
use App\Modules\Wms\Services\CostTimelineReader;
use App\Modules\Wms\Models\CostRevaluationRun;
use App\Modules\Wms\Services\CostRevaluationPreflightService;
use App\Modules\Wms\Services\CostRevaluationReconciliationService;
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

Artisan::command('wms:benchmark-fanout {--nodes=100} {--confirm}', function (): void {
    if (DB::connection()->getDatabaseName() !== 'new_erp_benchmark') {
        $this->error('คำสั่งนี้อนุญาตเฉพาะฐานข้อมูล new_erp_benchmark เท่านั้น');

        return;
    }
    if (! (bool) $this->option('confirm')) {
        $this->error('ต้องระบุ --confirm เพื่อสร้าง benchmark fan-out');

        return;
    }

    $targetNodes = max(2, min((int) $this->option('nodes'), 1000));
    $warehouses = DB::table('warehouses')->where('code', 'like', 'OPS-SMOKE-B%')->orderBy('id')->get(['id', 'code']);
    $created = 0;
    $skipped = 0;

    foreach ($warehouses as $warehouse) {
        DB::transaction(function () use ($warehouse, $targetNodes, &$created, &$skipped): void {
            $seed = DB::table('wms_cost_allocations as allocations')
                ->join('wms_stock_movements as movements', 'movements.id', '=', 'allocations.stock_movement_id')
                ->where('allocations.warehouse_id', $warehouse->id)
                ->where('allocations.status', 'POSTED')
                ->orderBy('allocations.id')
                ->first([
                    'allocations.stock_cost_layer_id', 'allocations.item_id', 'allocations.uom_id', 'allocations.unit_cost',
                    'movements.business_date', 'movements.source_id', 'movements.source_reference',
                ]);
            if (! $seed) {
                $skipped++;

                return;
            }

            $existing = (int) DB::table('wms_cost_allocations')->where('warehouse_id', $warehouse->id)->where('item_id', $seed->item_id)->where('uom_id', $seed->uom_id)->where('method', 'AVG')->where('status', 'POSTED')->count();
            if ($existing >= $targetNodes) {
                $skipped++;

                return;
            }

            $now = now();
            for ($sequence = $existing + 1; $sequence <= $targetNodes; $sequence++) {
                $movementId = DB::table('wms_stock_movements')->insertGetId([
                    'warehouse_id' => $warehouse->id,
                    'item_id' => $seed->item_id,
                    'uom_id' => $seed->uom_id,
                    'movement_type' => 'RECEIPT',
                    'direction' => 'IN',
                    'status' => 'POSTED',
                    'quantity' => '1.00000000',
                    'base_quantity' => '1.00000000',
                    'business_date' => $seed->business_date,
                    'source_type' => 'PURCHASING',
                    'source_id' => $seed->source_id,
                    'source_reference' => $seed->source_reference,
                    'idempotency_key' => 'benchmark:fanout:'.$warehouse->id.':'.$sequence,
                    'metadata' => json_encode(['fixture' => 'wms-benchmark-fanout-v1', 'synthetic' => true, 'event_code' => 'supplier_invoice.inventory'], JSON_THROW_ON_ERROR),
                    'posted_at' => $now,
                    'created_by' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('wms_cost_allocations')->insert([
                    'stock_movement_id' => $movementId,
                    'stock_cost_layer_id' => $seed->stock_cost_layer_id,
                    'warehouse_id' => $warehouse->id,
                    'item_id' => $seed->item_id,
                    'uom_id' => $seed->uom_id,
                    'allocation_type' => 'RECEIPT',
                    'direction' => 'IN',
                    'cost_status' => 'FINAL',
                    'status' => 'POSTED',
                    'method' => 'AVG',
                    'policy_version' => 'costing-v1',
                    'revision' => 0,
                    'quantity' => '1.00000000',
                    'unit_cost' => $seed->unit_cost,
                    'value' => $seed->unit_cost,
                    'business_date' => $seed->business_date,
                    'idempotency_key' => 'benchmark:fanout:allocation:'.$warehouse->id.':'.$sequence,
                    'metadata' => json_encode(['fixture' => 'wms-benchmark-fanout-v1', 'synthetic' => true], JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $created++;
            }
        }, 3);
    }

    $this->info(json_encode(['database' => DB::connection()->getDatabaseName(), 'target_nodes_per_partition' => $targetNodes, 'partitions' => $warehouses->count(), 'created_nodes' => $created, 'skipped_partitions' => $skipped], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
})->purpose('สร้าง synthetic high-fan-out ledger fixture เฉพาะฐาน new_erp_benchmark แบบ bounded ต่อ partition');

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

Artisan::command('wms:cost-benchmark {--partitions=3} {--page=250} {--from=}', function (): void {
    $partitionLimit = max(1, min((int) $this->option('partitions'), 20));
    $pageLimit = max(1, min((int) $this->option('page'), 1000));
    $from = (string) $this->option('from');
    $query = DB::table('wms_cost_allocations')
        ->where('status', 'POSTED')
        ->whereNotNull('business_date')
        ->whereIn('method', ['AVG', 'FIFO'])
        ->selectRaw('warehouse_id, item_id, uom_id, method, MIN(business_date) AS first_date, COUNT(*) AS nodes')
        ->groupBy('warehouse_id', 'item_id', 'uom_id', 'method')
        ->orderByDesc('nodes')
        ->limit($partitionLimit);
    $partitions = $query->get();
    if ($partitions->isEmpty()) {
        $this->warn('ไม่พบ Posted cost partition สำหรับ benchmark');

        return;
    }

    $reader = app(CostTimelineReader::class);
    $startedAt = hrtime(true);
    $startedMemory = memory_get_usage(true);
    $results = [];
    foreach ($partitions as $partition) {
        $partitionStartedAt = hrtime(true);
        $impactStart = $from !== '' ? $from : (string) $partition->first_date;
        $page = $reader->read((int) $partition->warehouse_id, (int) $partition->item_id, (int) $partition->uom_id, (string) $partition->method, $impactStart, null, $pageLimit);
        $elapsedMs = (hrtime(true) - $partitionStartedAt) / 1_000_000;
        $results[] = [
            'partition' => implode(':', [(int) $partition->warehouse_id, (int) $partition->item_id, (int) $partition->uom_id, strtoupper((string) $partition->method)]),
            'source_nodes' => (int) $partition->nodes,
            'page_limit' => $pageLimit,
            'nodes_scanned' => (int) data_get($page, 'page.nodes_scanned', 0),
            'has_more' => (bool) data_get($page, 'page.has_more', false),
            'anchor_status' => data_get($page, 'anchor.status'),
            'blockers' => data_get($page, 'blockers', []),
            'elapsed_ms' => round($elapsedMs, 3),
            'rows_per_second' => $elapsedMs > 0 ? round(((int) data_get($page, 'page.nodes_scanned', 0)) / ($elapsedMs / 1000), 2) : null,
        ];
    }
    $queue = DB::table('jobs')->where('queue', (string) config('erp.inventory.revaluation_queue', 'cost-propagation'))->count();
    $totalMs = (hrtime(true) - $startedAt) / 1_000_000;
    $this->line(json_encode([
        'read_only' => true,
        'partitions' => $results,
        'queue' => ['name' => config('erp.inventory.revaluation_queue', 'cost-propagation'), 'pending_jobs' => $queue],
        'total_elapsed_ms' => round($totalMs, 3),
        'peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        'memory_delta_mb' => round((memory_get_usage(true) - $startedMemory) / 1024 / 1024, 2),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
})->purpose('Read-only benchmark ของ Cost Timeline partition และ queue lag');

Artisan::command('wms:cost-health {--json}', function (): void {
    $queueName = (string) config('erp.inventory.revaluation_queue', 'cost-propagation');
    $leaseSeconds = max(90, min((int) config('erp.inventory.revaluation_calculation_lease_seconds', 120), 600));
    $now = now();
    $openStatuses = ['QUEUED', 'CALCULATING', 'WAITING_CONTINUATION', 'FAILED_RETRYABLE', 'LIMIT_REACHED', 'REQUIRES_REVIEW'];
    $runs = DB::table('wms_cost_revaluation_runs')
        ->whereIn('status', $openStatuses)
        ->orderBy('created_at')
        ->limit(1000)
        ->get(['id', 'batch_id', 'status', 'created_at', 'heartbeat_at', 'last_error']);
    $staleRuns = $runs->filter(fn ($run): bool => in_array($run->status, ['CALCULATING', 'WAITING_CONTINUATION'], true)
        && $run->heartbeat_at !== null
        && $run->heartbeat_at < $now->copy()->subSeconds($leaseSeconds));
    $pendingJobs = DB::table('jobs')->where('queue', $queueName)->count();
    $failedJobs = DB::table('failed_jobs')->where('queue', $queueName)->count();
    $pendingApproval = DB::table('wms_cost_revaluation_runs')->where('status', 'PENDING_APPROVAL')->count();
    $reviewRuns = $runs->whereIn('status', ['REQUIRES_REVIEW', 'LIMIT_REACHED', 'FAILED_RETRYABLE']);
    $invalidPostedRuns = DB::table('wms_cost_revaluation_runs as runs')
        ->whereIn('runs.status', ['GL_POSTED', 'COMPLETED'])
        ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('wms_cost_revaluation_deltas as deltas')->whereColumn('deltas.run_id', 'runs.id'))
        ->pluck('runs.id');
    $alerts = [];
    if ($staleRuns->isNotEmpty()) {
        $alerts[] = 'STALE_RUN_LEASE';
    }
    if ($failedJobs > 0) {
        $alerts[] = 'FAILED_QUEUE_JOB';
    }
    if ($reviewRuns->isNotEmpty()) {
        $alerts[] = 'RUN_REQUIRES_REVIEW';
    }
    if ($invalidPostedRuns->isNotEmpty()) {
        $alerts[] = 'POSTED_RUN_WITHOUT_DELTA';
    }
    $result = [
        'healthy' => $alerts === [],
        'read_only' => true,
        'checked_at' => $now->toIso8601String(),
        'alerts' => $alerts,
        'queue' => ['name' => $queueName, 'pending_jobs' => $pendingJobs, 'failed_jobs' => $failedJobs],
        'runs' => [
            'open_count' => $runs->count(),
            'pending_approval_count' => $pendingApproval,
            'stale_count' => $staleRuns->count(),
            'review_count' => $reviewRuns->count(),
            'oldest_open_created_at' => $runs->first()?->created_at,
            'stale_ids' => $staleRuns->pluck('id')->values()->all(),
            'review_ids' => $reviewRuns->pluck('id')->values()->all(),
            'invalid_posted_ids' => $invalidPostedRuns->values()->all(),
        ],
        'feature_gates' => [
            'auto_trigger' => (bool) config('erp.inventory.revaluation_auto_trigger_enabled', false),
            'apply' => (bool) config('erp.inventory.revaluation_apply_enabled', false),
            'gl_posting' => (bool) config('erp.inventory.revaluation_gl_posting_enabled', false),
        ],
    ];
    $output = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ((bool) $this->option('json')) {
        $this->line($output);
    } else {
        $this->line($result['healthy'] ? 'WMS Cost Health: PASS' : 'WMS Cost Health: ALERT');
        $this->line($output);
    }
    if (! $result['healthy']) {
        $this->error('Cost propagation health check พบ alert ที่ต้องตรวจสอบ');
        $this->fail('Health check failed', 2);
    }
})->purpose('Read-only health/alert check ของ Cost Propagation queue และ Revaluation runs');

Artisan::command('wms:cost-signoff-report {--run=} {--json}', function (): void {
    $statuses = ['PENDING_APPROVAL', 'APPROVED', 'STOCK_PROJECTED', 'GL_POSTED', 'COMPLETED', 'REQUIRES_REVIEW', 'LIMIT_REACHED', 'FAILED_RETRYABLE'];
    $query = CostRevaluationRun::query()->with('batch:id,source_document_type,source_document_id,source_document_reference')->whereIn('status', $statuses)->orderBy('id');
    if ((int) $this->option('run') > 0) {
        $query->whereKey((int) $this->option('run'));
    } else {
        $query->limit(100);
    }
    $preflight = app(CostRevaluationPreflightService::class);
    $reconciliation = app(CostRevaluationReconciliationService::class);
    $rows = $query->get()->map(function (CostRevaluationRun $run) use ($preflight, $reconciliation): array {
        $preflightResult = $preflight->check($run);
        $reconciliationResult = $reconciliation->check($run);

        return [
            'run_id' => (int) $run->id,
            'batch_id' => $run->batch_id ? (int) $run->batch_id : null,
            'status' => $run->status,
            'source' => [
                'type' => $run->batch?->source_document_type,
                'id' => $run->batch?->source_document_id,
                'reference' => $run->batch?->source_document_reference,
            ],
            'preflight' => ['ready' => $preflightResult['ready'], 'blockers' => $preflightResult['blockers']],
            'reconciliation' => ['ready' => $reconciliationResult['ready'], 'blockers' => $reconciliationResult['blockers'], 'totals' => $reconciliationResult['totals']],
            'accounting_decision' => $preflightResult['ready'] && $reconciliationResult['ready'] ? 'READY_FOR_ACCOUNTING_REVIEW' : 'BLOCKED',
        ];
    })->values()->all();
    $result = ['read_only' => true, 'generated_at' => now()->toIso8601String(), 'count' => count($rows), 'runs' => $rows];
    $output = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if ((bool) $this->option('json')) {
        $this->line($output);
    } else {
        $this->line($output);
    }
})->purpose('Read-only Accounting sign-off report ของ Cost Revaluation Run');

Artisan::command('wms:cost-gate-readiness {--json}', function (): void {
    $queueName = (string) config('erp.inventory.revaluation_queue', 'cost-propagation');
    $leaseSeconds = max(90, min((int) config('erp.inventory.revaluation_calculation_lease_seconds', 120), 600));
    $reviewCount = DB::table('wms_cost_revaluation_runs')->whereIn('status', ['REQUIRES_REVIEW', 'LIMIT_REACHED', 'FAILED_RETRYABLE'])->count();
    $invalidPostedCount = DB::table('wms_cost_revaluation_runs as runs')
        ->whereIn('runs.status', ['GL_POSTED', 'COMPLETED'])
        ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('wms_cost_revaluation_deltas as deltas')->whereColumn('deltas.run_id', 'runs.id'))
        ->count();
    $pendingJobs = DB::table('jobs')->where('queue', $queueName)->count();
    $failedJobs = DB::table('failed_jobs')->where('queue', $queueName)->count();
    $staleRuns = DB::table('wms_cost_revaluation_runs')->whereIn('status', ['CALCULATING', 'WAITING_CONTINUATION'])->whereNotNull('heartbeat_at')->where('heartbeat_at', '<', now()->subSeconds($leaseSeconds))->count();
    $commonBlockers = [];
    if ($reviewCount > 0) {
        $commonBlockers[] = 'RUN_REQUIRES_REVIEW';
    }
    if ($failedJobs > 0) {
        $commonBlockers[] = 'FAILED_QUEUE_JOB';
    }
    if ($staleRuns > 0) {
        $commonBlockers[] = 'STALE_RUN_LEASE';
    }
    if ($invalidPostedCount > 0) {
        $commonBlockers[] = 'POSTED_RUN_WITHOUT_DELTA';
    }
    $result = [
        'read_only' => true,
        'checked_at' => now()->toIso8601String(),
        'evidence' => ['queue' => $queueName, 'pending_jobs' => $pendingJobs, 'failed_jobs' => $failedJobs, 'review_runs' => $reviewCount, 'stale_runs' => $staleRuns, 'invalid_posted_runs' => $invalidPostedCount],
        'gates' => [
            'auto_trigger' => ['ready' => $commonBlockers === [], 'blockers' => $commonBlockers],
            'apply' => ['ready' => $commonBlockers === [], 'blockers' => $commonBlockers],
            'gl_posting' => ['ready' => $commonBlockers === [], 'blockers' => $commonBlockers],
        ],
        'current_config' => [
            'auto_trigger' => (bool) config('erp.inventory.revaluation_auto_trigger_enabled', false),
            'apply' => (bool) config('erp.inventory.revaluation_apply_enabled', false),
            'gl_posting' => (bool) config('erp.inventory.revaluation_gl_posting_enabled', false),
        ],
    ];
    $output = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $this->line($output);
    if (collect($result['gates'])->contains(fn (array $gate): bool => ! $gate['ready'])) {
        $this->fail('Cost feature gate readiness ยังไม่ผ่าน', 2);
    }
})->purpose('Read-only staged feature gate readiness ของ Cost Propagation');

Artisan::command('asset:maintenance-alerts', function (): void {
    $today = today();
    $schedules = AssetMaintenanceSchedule::query()->where('is_active', true)->whereDate('next_due_date', '<=', $today->copy()->addDays(7))->get();
    $schedules->each(fn (AssetMaintenanceSchedule $schedule) => $schedule->update(['last_alerted_at' => now()]));
    $this->info("Maintenance alerts: {$schedules->count()}");
})->purpose('บันทึกการตรวจแผนบำรุงรักษาที่ใกล้ครบกำหนดหรือเกินกำหนด โดยไม่สร้างใบแจ้งซ่อม');

Schedule::command('asset:maintenance-alerts')->dailyAt('08:00')->withoutOverlapping()->onOneServer();

Artisan::command('wms:repair-pos-reversal-links {warehouse_id} {--apply} {--actor=1}', function (int $warehouse_id): void {
    $rows = DB::table('wms_cost_allocations as allocations')
        ->join('wms_stock_movements as movements', 'movements.id', '=', 'allocations.stock_movement_id')
        ->where('allocations.warehouse_id', $warehouse_id)->where('allocations.status', '!=', 'REVERSED')
        ->whereNull('allocations.journal_entry_id')->where('movements.source_type', 'POS')
        ->whereNotNull('movements.metadata')->orderBy('allocations.id')
        ->get(['allocations.id', 'allocations.item_id', 'movements.metadata']);
    $actor = User::query()->find((int) $this->option('actor'));
    $request = Illuminate\Http\Request::create('/wms/repair-pos-reversal-links', 'POST');
    $results = [];
    foreach ($rows as $row) {
        $metadata = json_decode((string) $row->metadata, true);
        $originalMovementId = (int) ($metadata['reversal_of_movement_id'] ?? 0);
        $originalLink = $originalMovementId > 0 ? DB::table('wms_cost_allocation_journal_lines as links')
            ->join('wms_cost_allocations as originals', 'originals.id', '=', 'links.allocation_id')
            ->where('originals.stock_movement_id', $originalMovementId)->where('originals.item_id', $row->item_id)
            ->orderBy('links.id')->first(['links.journal_entry_line_id']) : null;
        $originalLine = $originalLink ? DB::table('journal_entry_lines')->where('id', $originalLink->journal_entry_line_id)->first(['journal_entry_id', 'line_number']) : null;
        $reversalJournal = $originalLine ? DB::table('journal_entries')->where('reversal_of_id', $originalLine->journal_entry_id)->where('status', 'POSTED')->first(['id', 'entry_number']) : null;
        $candidate = $reversalJournal ? DB::table('journal_entry_lines')->where('journal_entry_id', $reversalJournal->id)->where('line_number', $originalLine->line_number)->where('subledger_type', 'ITEM')->where('subledger_id', (string) $row->item_id)->get(['id']) : collect();
        $result = ['allocation_id' => (int) $row->id, 'reversal_journal' => $reversalJournal?->entry_number, 'status' => 'SKIPPED'];
        if ($candidate->count() === 1) {
            $result['journal_entry_line_id'] = (int) $candidate->first()->id;
            if ($this->option('apply')) {
                if (! $actor) { $this->error('ไม่พบ actor ที่ระบุด้วย --actor'); return; }
                DB::transaction(function () use ($row, $candidate, $actor, $request): void {
                    $allocation = CostAllocation::query()->lockForUpdate()->findOrFail($row->id);
                    $before = $allocation->only(['journal_entry_id', 'status']);
                    app(InventoryCostAllocationService::class)->linkJournalLineWithinTransaction($allocation, \App\Modules\Accounting\Models\JournalEntryLine::query()->whereKey($candidate->first()->id)->firstOrFail());
                    $updated = $allocation->fresh();
                    app(\App\Modules\Platform\Services\AuditLogger::class)->record('wms.cost_allocation.legacy_reversal_link_repaired', $updated, $before, $updated->only(['journal_entry_id', 'status']), $actor, $request);
                });
                $result['status'] = 'REPAIRED';
            } else { $result['status'] = 'READY_TO_REPAIR'; }
        }
        $results[] = $result;
    }
    $this->line(json_encode($results, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
})->purpose('ตรวจและซ่อม POS reversal allocation linkage ด้วยหลักฐาน Journal แบบ idempotent');

Artisan::command('wms:correct-legacy-duplicate-allocations {warehouse_id} {--apply} {--actor=1}', function (int $warehouse_id): void {
    $service = app(\App\Modules\Wms\Services\CostAllocationCorrectionService::class);
    $actor = User::query()->find((int) $this->option('actor'));
    $request = Illuminate\Http\Request::create('/wms/correct-legacy-duplicate-allocations', 'POST');
    $candidates = $service->candidates($warehouse_id);
    if (! $this->option('apply')) {
        $this->line(json_encode(collect($candidates)->map(fn (array $candidate): array => ['allocation_id' => $candidate['duplicate']->id, 'canonical_allocation_id' => $candidate['canonical']->id, 'journal_entry_line_id' => $candidate['journal_entry_line_id']])->all(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return;
    }
    if (! $actor) {
        $this->error('ไม่พบ actor ที่ระบุด้วย --actor');
        return;
    }
    $this->line(json_encode($service->apply($warehouse_id, $actor, $request), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
})->purpose('สร้าง immutable correction record สำหรับ legacy duplicate allocation โดยไม่แก้หรือลบ POSTED allocation');
