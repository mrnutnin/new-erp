<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Warehouse;
use App\Modules\Settings\Services\GlobalSettings;
use App\Modules\Wms\Models\CostAllocation;
use App\Modules\Wms\Services\InventoryReconciliationService;
use App\Modules\Wms\Services\RecostGlPostingService;
use App\Modules\Wms\Services\StockMovementService;
use App\Modules\Wms\Services\StockRecostService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class RecostRuntimeMySqlIntegrationReadinessTest extends TestCase
{
    public function test_negative_receipt_resolves_and_recost_gl_is_idempotent_with_positive_and_negative_delta(): void
    {
        if (config('database.default') !== 'mysql' || env('ERP_RUN_MYSQL_INTEGRATION') !== '1') {
            $this->markTestSkipped('ต้องรันใน dedicated MySQL integration process ด้วย ERP_RUN_MYSQL_INTEGRATION=1 เท่านั้น');
        }

        $settings = DB::table('company_settings')->where('id', 1)->first();
        $oldVersion = (int) $settings->settings_version;
        $counts = $this->counts();
        DB::beginTransaction();
        try {
            DB::table('company_settings')->where('id', 1)->update([
                'allow_negative_stock' => 1,
                'settings_version' => $oldVersion + 1,
                'updated_at' => now(),
            ]);
            app(GlobalSettings::class)->forget($oldVersion);

            foreach ([
                ['POSITIVE', 305, 26, 52, '10.00', '20.00', '500.00000000'],
                ['NEGATIVE', 306, 27, 54, '20.00', '10.00', '-500.00000000'],
            ] as [$label, $warehouseId, $itemId, $uomId, $initialCost, $actualCost, $expectedDelta]) {
                $this->assertRecostCase($label, $warehouseId, $itemId, $uomId, $initialCost, $actualCost, $expectedDelta);
            }
        } finally {
            DB::rollBack();
            DB::table('company_settings')->where('id', 1)->update(['settings_version' => $oldVersion]);
            Cache::forget('global-settings.version');
            Cache::forget('global-settings.v'.($oldVersion + 1));
        }

        $this->assertSame($counts, $this->counts(), 'rollback-only Recost fixture must not leave persistent rows');
    }

    private function assertRecostCase(string $label, int $warehouseId, int $itemId, int $uomId, string $initialCost, string $actualCost, string $expectedDelta): void
    {
        $movement = app(StockMovementService::class);
        $tag = 'RT-'.strtoupper(bin2hex(random_bytes(4))).'-'.$label;
        $this->movement($movement, $tag, $warehouseId, $itemId, $uomId, 'IN', '100', 'INIT', $initialCost);
        $this->movement($movement, $tag, $warehouseId, $itemId, $uomId, 'OUT', '150', 'ISSUE');
        $resolution = $this->movement($movement, $tag, $warehouseId, $itemId, $uomId, 'IN', '50', 'RESOLVE', $actualCost);

        $resolved = app(StockRecostService::class)->processReceipt($resolution->id, $actualCost);
        $this->assertSame(1, $resolved);
        $allocation = CostAllocation::query()->where('stock_movement_id', $resolution->id)->where('allocation_type', 'RECOST')->sole();
        $this->assertSame($expectedDelta, (string) $allocation->value);

        $reconBefore = app(InventoryReconciliationService::class)->totals('2026-08-24', $warehouseId, $itemId);
        $journal = app(RecostGlPostingService::class)->post($allocation, Warehouse::query()->findOrFail($warehouseId), ['period_open' => true, 'reconciliation_ready' => true], User::query()->findOrFail(1));
        $retry = app(RecostGlPostingService::class)->post($allocation->fresh(), Warehouse::query()->findOrFail($warehouseId), ['period_open' => true, 'reconciliation_ready' => true], User::query()->findOrFail(1));
        $linked = $allocation->fresh();
        $this->assertSame($journal->id, $retry->id, $label.' retry must reuse Journal');
        $this->assertSame($journal->id, (int) $linked->journal_entry_id);
        $this->assertSame(1, $linked->journalLineLinks()->count());
        $reconAfter = app(InventoryReconciliationService::class)->totals('2026-08-24', $warehouseId, $itemId);
        $this->assertNotSame((string) $reconBefore['allocation_vs_gl_difference'], (string) $reconAfter['allocation_vs_gl_difference']);
        $this->assertCount(2, $journal->fresh()->lines);
    }

    private function movement(StockMovementService $service, string $tag, int $warehouseId, int $itemId, int $uomId, string $direction, string $quantity, string $suffix, ?string $unitCost = null)
    {
        $movement = $service->recordIntent([
            'warehouse_id' => $warehouseId, 'item_id' => $itemId, 'uom_id' => $uomId,
            'movement_type' => $direction === 'IN' ? 'RECEIPT' : 'ISSUE', 'direction' => $direction,
            'quantity' => $quantity, 'base_quantity' => $quantity, 'business_date' => '2026-08-24',
            'source_type' => 'RECOST_TEST', 'source_id' => $tag.'-'.$suffix, 'source_reference' => $tag.'-'.$suffix,
            'idempotency_key' => 'recost-runtime:'.$tag.':'.$suffix,
            'metadata' => $unitCost === null ? null : ['unit_cost' => $unitCost, 'unit_cost_trusted' => true],
        ]);

        return $service->postWithinTransaction($movement);
    }

    /** @return array<string, int> */
    private function counts(): array
    {
        return collect(['wms_stock_movements', 'wms_cost_allocations', 'wms_stock_cost_layers', 'wms_cost_allocation_journal_lines', 'journal_entries', 'wms_cost_recalculation_requests'])
            ->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
