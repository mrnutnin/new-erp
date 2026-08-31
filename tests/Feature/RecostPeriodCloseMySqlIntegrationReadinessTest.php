<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\FiscalPeriod;
use App\Modules\Accounting\Support\PeriodCloseGate;
use App\Modules\Wms\Services\RecostQueueHealth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Opt-in local-MySQL contract. All rows are created and changed in one
 * explicit transaction and rolled back; this test never seeds persistent data.
 */
final class RecostPeriodCloseMySqlIntegrationReadinessTest extends TestCase
{
    public function test_pending_stale_retry_and_period_close_gate_are_rollback_safe(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $movement = DB::table('wms_stock_movements')
            ->where('status', 'POSTED')
            ->orderBy('id')
            ->first(['id', 'warehouse_id', 'item_id', 'business_date']);
        $period = $movement
            ? FiscalPeriod::query()->whereDate('start_date', '<=', $movement->business_date)->whereDate('end_date', '>=', $movement->business_date)->first()
            : null;

        if (! $movement || ! $period) {
            $this->markTestSkipped('ต้องมี Posted Movement และ Fiscal Period ที่ครอบคลุม business date ใน local MySQL');
        }

        $before = DB::table('wms_cost_recalculation_requests')->count();
        DB::beginTransaction();
        try {
            $requestId = DB::table('wms_cost_recalculation_requests')->insertGetId([
                'idempotency_key' => 'MYSQL-ROLLBACK-RECOST-'.bin2hex(random_bytes(6)),
                'warehouse_id' => $movement->warehouse_id,
                'item_id' => $movement->item_id,
                'trigger_movement_id' => $movement->id,
                'status' => 'PENDING',
                'attempts' => 0,
                'last_error' => null,
                'resolved_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $health = app(RecostQueueHealth::class);
            $this->assertSame(1, $health->summary((int) $movement->warehouse_id)['PENDING']);
            $this->assertTrue(collect(PeriodCloseGate::failures($period))->contains(fn (string $failure): bool => str_contains($failure, 'Recost request')));

            DB::table('wms_cost_recalculation_requests')->where('id', $requestId)->update([
                'status' => 'PROCESSING',
                'updated_at' => now()->subMinutes(10),
            ]);
            $this->assertSame(1, $health->markStale(1));
            $this->assertSame('STALE', DB::table('wms_cost_recalculation_requests')->where('id', $requestId)->value('status'));

            $health->retry($requestId, (int) $movement->warehouse_id);
            $this->assertSame('PENDING', DB::table('wms_cost_recalculation_requests')->where('id', $requestId)->value('status'));

            DB::table('wms_cost_recalculation_requests')->where('id', $requestId)->update(['status' => 'RESOLVED', 'resolved_at' => now()]);
            $this->assertFalse(collect(PeriodCloseGate::failures($period))->contains(fn (string $failure): bool => str_contains($failure, 'Recost request')));
        } finally {
            DB::rollBack();
        }

        $this->assertSame($before, DB::table('wms_cost_recalculation_requests')->count(), 'rollback-only fixture must leave persistent requests unchanged');
    }
}
